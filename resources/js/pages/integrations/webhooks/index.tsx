import { Head, Link, router } from '@inertiajs/react';
import { MoreHorizontal, Plus, Webhook } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { EndpointStatusBadge } from '@/components/integrations/badges';
import { EndpointFormDialog } from '@/components/integrations/endpoint-form-dialog';
import {
    IntegrationsCard,
    IntegrationsShell,
} from '@/components/integrations/integrations-shell';
import { OneTimeSecret } from '@/components/integrations/one-time-secret';
import type {
    EventOption,
    RevealedSecret,
    WebhookEndpointRow,
    WebhookLimits,
} from '@/components/integrations/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDuration, formatTimeAgo } from '@/lib/format';
import { index as integrationsIndex } from '@/routes/integrations';
import {
    destroy,
    index as webhooksIndex,
    pause,
    resume,
    show,
    test,
} from '@/routes/integrations/webhooks';

interface WebhooksIndexProps {
    endpoints: WebhookEndpointRow[];
    catalog: EventOption[];
    limits: WebhookLimits;
    revealed_secret: RevealedSecret | null;
}

/**
 * Integrações → Webhooks (Fase 2 §2.16; mock "App - Integracoes"). Endpoints
 * da organização, cadastrados pela tela ou criados por REST Hooks (n8n,
 * Zapier, Make). Contrato das props: docs/fase-2/webhooks.md §9.
 */
export default function WebhooksIndex({
    endpoints,
    catalog,
    limits,
    revealed_secret,
}: WebhooksIndexProps) {
    const [creating, setCreating] = useState(false);
    const [removing, setRemoving] = useState<WebhookEndpointRow | null>(null);
    const [processing, setProcessing] = useState(false);
    const [revealed, setRevealed] = useState<RevealedSecret | null>(null);

    useEffect(() => {
        if (revealed_secret) {
            setRevealed(revealed_secret);
            router.replaceProp('revealed_secret', null);
        }
    }, [revealed_secret]);

    const atLimit = endpoints.length >= limits.max_endpoints;
    const labels = Object.fromEntries(catalog.map((e) => [e.value, e.label]));

    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    const remove = () => {
        if (!removing) {
            return;
        }

        setProcessing(true);
        router.delete(destroy.url(removing.id), {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setRemoving(null);
            },
        });
    };

    const addButton = (
        <Button
            variant="outline"
            onClick={() => setCreating(true)}
            disabled={atLimit}
            title={
                atLimit
                    ? `Limite de ${limits.max_endpoints} endpoints atingido`
                    : undefined
            }
        >
            <Plus className="size-[15px]" strokeWidth={2.5} />
            Adicionar endpoint
        </Button>
    );

    const backoff = limits.backoff_seconds
        .map((seconds) => formatDuration(Math.round(seconds / 60)))
        .join(', ');

    return (
        <>
            <Head title="Webhooks" />
            <IntegrationsShell
                active="webhooks"
                title="Webhooks"
                subtitle="Endpoints que recebem os eventos da sua conta em tempo real, com entrega assinada e novas tentativas."
            >
                <div className="flex flex-wrap items-start gap-5">
                    <div className="flex min-w-0 flex-[2_1_520px] flex-col gap-5">
                        {revealed && (
                            <OneTimeSecret
                                title="Segredo do endpoint gerado"
                                value={revealed.secret}
                                onDismiss={() => setRevealed(null)}
                            />
                        )}

                        <IntegrationsCard
                            title="Endpoints de webhook"
                            description={`${endpoints.length} de ${limits.max_endpoints}. Novas tentativas por até ${limits.max_attempts} vezes quando o seu servidor não responde 2xx.`}
                            actions={endpoints.length > 0 ? addButton : null}
                        >
                            {endpoints.length === 0 ? (
                                <EmptyState
                                    icon={Webhook}
                                    title="Nenhum endpoint ainda"
                                    description="Cadastre uma URL HTTPS do seu sistema para receber avisos quando um documento for enviado, assinado, recusado ou concluído. Conectores como n8n, Zapier e Make criam os deles pela API."
                                    action={addButton}
                                />
                            ) : (
                                <ul className="divide-border border-border divide-y border-t">
                                    {endpoints.map((endpoint) => (
                                        <li
                                            key={endpoint.id}
                                            className="hover:bg-row-hover flex flex-wrap items-center gap-3.5 px-5 py-3"
                                        >
                                            <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                                                <Webhook className="size-4" />
                                            </span>
                                            <span className="min-w-0 flex-[1_1_240px]">
                                                <Link
                                                    href={show.url(endpoint.id)}
                                                    className="hover:text-primary block truncate font-mono text-[12.5px] font-semibold"
                                                >
                                                    {endpoint.url}
                                                </Link>
                                                {endpoint.description && (
                                                    <span className="text-text-secondary block truncate text-[12.5px]">
                                                        {endpoint.description}
                                                    </span>
                                                )}
                                                <span className="mt-1.5 flex flex-wrap gap-1">
                                                    {endpoint.source ===
                                                        'rest_hook' && (
                                                        <Badge
                                                            variant="info"
                                                            title="Criado por um conector (n8n, Zapier, Make) pela API"
                                                        >
                                                            REST Hook
                                                        </Badge>
                                                    )}
                                                    {endpoint.all_events ? (
                                                        <EventChip>
                                                            todos os eventos
                                                        </EventChip>
                                                    ) : (
                                                        endpoint.events.map(
                                                            (event) => (
                                                                <EventChip
                                                                    key={event}
                                                                    title={
                                                                        labels[
                                                                            event
                                                                        ]
                                                                    }
                                                                >
                                                                    {event}
                                                                </EventChip>
                                                            ),
                                                        )
                                                    )}
                                                </span>
                                            </span>
                                            <span className="min-w-[130px] text-right">
                                                <span className="block text-[12.5px] font-semibold">
                                                    {endpoint.last_success_at
                                                        ? `entregue ${formatTimeAgo(endpoint.last_success_at)}`
                                                        : 'sem entregas ainda'}
                                                </span>
                                                <span className="text-muted-foreground block text-[11.5px]">
                                                    {endpoint.status ===
                                                    'paused'
                                                        ? (endpoint.paused_reason_label ??
                                                          'Pausado')
                                                        : endpoint.consecutive_failures >
                                                            0
                                                          ? `${endpoint.consecutive_failures} falhas seguidas`
                                                          : endpoint.last_failure_at
                                                            ? `última falha ${formatTimeAgo(endpoint.last_failure_at)}`
                                                            : 'nenhuma falha'}
                                                </span>
                                            </span>
                                            <EndpointStatusBadge
                                                status={endpoint.status}
                                                label={endpoint.status_label}
                                            />
                                            <span className="flex items-center gap-1.5">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="xxs"
                                                    onClick={() =>
                                                        post(
                                                            test.url(
                                                                endpoint.id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Testar
                                                </Button>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-xs"
                                                            aria-label="Mais ações"
                                                        >
                                                            <MoreHorizontal className="size-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <Link
                                                                href={show.url(
                                                                    endpoint.id,
                                                                )}
                                                            >
                                                                Ver entregas e
                                                                segredo
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        {endpoint.status ===
                                                        'active' ? (
                                                            <DropdownMenuItem
                                                                onSelect={() =>
                                                                    post(
                                                                        pause.url(
                                                                            endpoint.id,
                                                                        ),
                                                                    )
                                                                }
                                                            >
                                                                Pausar
                                                            </DropdownMenuItem>
                                                        ) : (
                                                            <DropdownMenuItem
                                                                onSelect={() =>
                                                                    post(
                                                                        resume.url(
                                                                            endpoint.id,
                                                                        ),
                                                                    )
                                                                }
                                                            >
                                                                Reativar
                                                            </DropdownMenuItem>
                                                        )}
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            className="text-danger"
                                                            onSelect={() =>
                                                                setRemoving(
                                                                    endpoint,
                                                                )
                                                            }
                                                        >
                                                            Remover
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </IntegrationsCard>
                    </div>

                    <aside className="flex min-w-0 flex-[1_1_280px] flex-col gap-4">
                        <IntegrationsCard
                            title="Entregas assinadas"
                            className="pb-4"
                        >
                            <div className="text-text-secondary flex flex-col gap-2 px-5 text-[13px] leading-[1.55]">
                                <p>
                                    Cada entrega leva o cabeçalho{' '}
                                    <code className="bg-muted rounded px-1 font-mono text-[12px]">
                                        X-AssinaVelox-Signature
                                    </code>{' '}
                                    com o HMAC SHA-256 de{' '}
                                    <code className="bg-muted rounded px-1 font-mono text-[12px]">
                                        {'{timestamp}.{corpo}'}
                                    </code>
                                    , usando o segredo do endpoint.
                                </p>
                                <p>
                                    Recuse entregas com o timestamp a mais de{' '}
                                    {Math.round(
                                        limits.signature_tolerance_seconds / 60,
                                    )}{' '}
                                    minutos do seu relógio e deduplique pelo{' '}
                                    <code className="bg-muted rounded px-1 font-mono text-[12px]">
                                        X-AssinaVelox-Delivery-Id
                                    </code>
                                    .
                                </p>
                                <Link
                                    href={`${integrationsIndex.url()}#assinatura`}
                                    className="text-primary text-[13px] font-semibold"
                                >
                                    Exemplos de validação →
                                </Link>
                            </div>
                        </IntegrationsCard>
                        <IntegrationsCard
                            title="Tentativas e pausa"
                            className="pb-4"
                        >
                            <ul className="text-text-secondary flex list-disc flex-col gap-1.5 pr-5 pl-9 text-[13px] leading-[1.55]">
                                <li>
                                    Responda 2xx em até {limits.timeout_seconds}{' '}
                                    s; processe depois.
                                </li>
                                <li>Novas tentativas após: {backoff}.</li>
                                <li>
                                    Tempo esgotado conta como resultado
                                    desconhecido: pode chegar em duplicidade.
                                </li>
                                <li>
                                    Pausa automática após{' '}
                                    {limits.pause_after_consecutive_failures}{' '}
                                    falhas seguidas, com aviso por e-mail.
                                </li>
                                <li>
                                    Histórico guardado por{' '}
                                    {limits.retention_days} dias.
                                </li>
                            </ul>
                        </IntegrationsCard>
                    </aside>
                </div>
            </IntegrationsShell>

            <EndpointFormDialog
                open={creating}
                onOpenChange={setCreating}
                catalog={catalog}
            />

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title="Remover este endpoint?"
                description={
                    removing?.source === 'rest_hook'
                        ? 'Ele foi criado por um conector (REST Hook). As entregas pendentes são canceladas; o conector pode recriá-lo ao ser reativado.'
                        : 'As entregas pendentes são canceladas e nenhum evento novo será enviado a ele.'
                }
                confirmLabel="Remover"
                destructive
                processing={processing}
                onConfirm={remove}
            />
        </>
    );
}

WebhooksIndex.layout = {
    breadcrumbs: [
        { title: 'API e integrações', href: integrationsIndex() },
        { title: 'Webhooks', href: webhooksIndex() },
    ],
};

function EventChip({ children, title }: { children: string; title?: string }) {
    return (
        <span
            title={title}
            className="bg-primary-soft text-primary rounded-[5px] px-1.5 py-px font-mono text-[11px] font-semibold"
        >
            {children}
        </span>
    );
}
