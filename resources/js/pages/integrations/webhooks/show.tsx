import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Inbox, RotateCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { CopyButton } from '@/components/copy-button';
import { EmptyState } from '@/components/empty-state';
import {
    DeliveryStatusBadge,
    EndpointStatusBadge,
    HttpStatusBadge,
} from '@/components/integrations/badges';
import { DeliverySheet } from '@/components/integrations/delivery-sheet';
import { EndpointFormDialog } from '@/components/integrations/endpoint-form-dialog';
import {
    IntegrationsCard,
    IntegrationsShell,
} from '@/components/integrations/integrations-shell';
import { OneTimeSecret } from '@/components/integrations/one-time-secret';
import type {
    EventOption,
    RevealedSecret,
    WebhookDeliveryRow,
    WebhookEndpointRow,
    WebhookLimits,
} from '@/components/integrations/types';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime, formatRelativeDateTime } from '@/lib/format';
import { index as integrationsIndex } from '@/routes/integrations';
import {
    destroy,
    index as webhooksIndex,
    pause,
    resume,
    show,
    test,
} from '@/routes/integrations/webhooks';
import { resend } from '@/routes/integrations/webhooks/deliveries';
import { expire_previous, rotate } from '@/routes/integrations/webhooks/secret';
import type { Paginated } from '@/types';
import type { BreadcrumbItem } from '@/types/navigation';

interface WebhookShowProps {
    endpoint: WebhookEndpointRow;
    deliveries: Paginated<WebhookDeliveryRow>;
    filters: { status: string | null; event: string | null };
    catalog: EventOption[];
    statuses: { value: string; label: string }[];
    limits: WebhookLimits;
    revealed_secret: RevealedSecret | null;
}

const ALL = 'all';

/**
 * Detalhe de um endpoint de webhook: segredo (exibido uma vez após criar ou
 * rotacionar), rotação com convivência, pausa, teste e histórico de entregas
 * com filtro, detalhe e reenvio (docs/fase-2/webhooks.md §9).
 */
export default function WebhookShow({
    endpoint,
    deliveries,
    filters,
    catalog,
    statuses,
    limits,
    revealed_secret,
}: WebhookShowProps) {
    const [revealed, setRevealed] = useState<RevealedSecret | null>(null);
    const [editing, setEditing] = useState(false);
    const [rotating, setRotating] = useState(false);
    const [overlap, setOverlap] = useState(
        String(limits.secret_rotation_overlap_hours),
    );
    const [removing, setRemoving] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [openDelivery, setOpenDelivery] = useState<string | null>(null);

    useEffect(() => {
        if (revealed_secret && revealed_secret.endpoint === endpoint.id) {
            setRevealed(revealed_secret);
            router.replaceProp('revealed_secret', null);
        }
    }, [revealed_secret, endpoint.id]);

    const post = (url: string, data: Record<string, unknown> = {}) =>
        router.post(url, data as never, { preserveScroll: true });

    const applyFilters = (next: Partial<WebhookShowProps['filters']>) => {
        const merged = { ...filters, ...next };
        const query: Record<string, string> = {};

        if (merged.status) {
            query.status = merged.status;
        }

        if (merged.event) {
            query.event = merged.event;
        }

        router.get(
            show.url(endpoint.id, { query }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const overlapOptions = [0, 24, 72, 168].filter(
        (hours) => hours <= limits.max_rotation_overlap_hours,
    );

    const doRotate = () => {
        setProcessing(true);
        router.post(
            rotate.url(endpoint.id),
            { overlap_hours: Number(overlap) },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setRotating(false);
                },
            },
        );
    };

    const doRemove = () => {
        setProcessing(true);
        router.delete(destroy.url(endpoint.id), {
            onFinish: () => {
                setProcessing(false);
                setRemoving(false);
            },
        });
    };

    const labels = Object.fromEntries(catalog.map((e) => [e.value, e.label]));

    return (
        <>
            <Head title={`Webhook · ${endpoint.host}`} />
            <IntegrationsShell
                active="webhooks"
                title={endpoint.host}
                subtitle={endpoint.description ?? 'Endpoint de webhook'}
            >
                <div>
                    <Button asChild variant="ghost" size="xxs">
                        <Link href={webhooksIndex.url()}>
                            <ArrowLeft className="size-3.5" />
                            Todos os endpoints
                        </Link>
                    </Button>
                </div>

                {revealed && (
                    <OneTimeSecret
                        title="Segredo do endpoint"
                        value={revealed.secret}
                        onDismiss={() => setRevealed(null)}
                    >
                        <p className="text-success text-[12.5px]">
                            Configure-o no seu sistema para validar o cabeçalho
                            X-AssinaVelox-Signature.
                        </p>
                    </OneTimeSecret>
                )}

                <div className="flex flex-wrap items-start gap-5">
                    <IntegrationsCard
                        className="min-w-0 flex-[2_1_520px]"
                        title={
                            <span className="flex flex-wrap items-center gap-2">
                                Endpoint
                                <EndpointStatusBadge
                                    status={endpoint.status}
                                    label={endpoint.status_label}
                                />
                                {endpoint.source === 'rest_hook' && (
                                    <Badge variant="info">REST Hook</Badge>
                                )}
                            </span>
                        }
                        actions={
                            <>
                                <Button
                                    variant="outline"
                                    size="xs"
                                    onClick={() => post(test.url(endpoint.id))}
                                >
                                    Enviar teste
                                </Button>
                                <Button
                                    variant="outline"
                                    size="xs"
                                    onClick={() => setEditing(true)}
                                >
                                    Editar
                                </Button>
                                {endpoint.status === 'active' ? (
                                    <Button
                                        variant="outline"
                                        size="xs"
                                        onClick={() =>
                                            post(pause.url(endpoint.id))
                                        }
                                    >
                                        Pausar
                                    </Button>
                                ) : (
                                    <Button
                                        size="xs"
                                        onClick={() =>
                                            post(resume.url(endpoint.id))
                                        }
                                    >
                                        Reativar
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    size="xs"
                                    className="hover:border-danger-border hover:bg-danger-bg hover:text-danger"
                                    onClick={() => setRemoving(true)}
                                >
                                    Remover
                                </Button>
                            </>
                        }
                    >
                        <dl className="grid grid-cols-[150px_minmax(0,1fr)] gap-x-4 gap-y-2.5 px-5 pb-5 text-[13px]">
                            <dt className="text-muted-foreground">URL</dt>
                            <dd className="flex min-w-0 items-center gap-1">
                                <code className="truncate font-mono text-[12.5px]">
                                    {endpoint.url}
                                </code>
                                <CopyButton value={endpoint.url} />
                            </dd>
                            {endpoint.status === 'paused' &&
                                endpoint.paused_reason_label && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Pausa
                                        </dt>
                                        <dd className="text-warning">
                                            {endpoint.paused_reason_label}
                                            {endpoint.paused_at &&
                                                ` · ${formatDateTime(endpoint.paused_at)}`}
                                        </dd>
                                    </>
                                )}
                            <dt className="text-muted-foreground">Eventos</dt>
                            <dd className="flex flex-wrap gap-1">
                                {endpoint.all_events ? (
                                    <span className="text-[13px]">
                                        Todos os eventos
                                    </span>
                                ) : (
                                    endpoint.events.map((event) => (
                                        <span
                                            key={event}
                                            title={labels[event]}
                                            className="bg-primary-soft text-primary rounded-[5px] px-1.5 py-px font-mono text-[11px] font-semibold"
                                        >
                                            {event}
                                        </span>
                                    ))
                                )}
                            </dd>
                            <dt className="text-muted-foreground">
                                Última entrega
                            </dt>
                            <dd>
                                {endpoint.last_success_at
                                    ? formatRelativeDateTime(
                                          endpoint.last_success_at,
                                      )
                                    : '—'}
                            </dd>
                            <dt className="text-muted-foreground">
                                Última falha
                            </dt>
                            <dd>
                                {endpoint.last_failure_at
                                    ? formatRelativeDateTime(
                                          endpoint.last_failure_at,
                                      )
                                    : '—'}
                                {endpoint.consecutive_failures > 0 &&
                                    ` · ${endpoint.consecutive_failures} seguidas`}
                            </dd>
                            <dt className="text-muted-foreground">
                                Responsável
                            </dt>
                            <dd>
                                {endpoint.created_by ?? '—'}
                                <span className="text-muted-foreground block text-[12px]">
                                    Recebe só eventos dos documentos que essa
                                    pessoa pode ver.
                                </span>
                            </dd>
                        </dl>
                    </IntegrationsCard>

                    <IntegrationsCard
                        className="min-w-0 flex-[1_1_280px] pb-5"
                        title="Segredo"
                        description="Assina o corpo (HMAC SHA-256) no cabeçalho X-AssinaVelox-Signature."
                    >
                        <div className="flex flex-col gap-3 px-5">
                            <code className="bg-background border-border text-foreground truncate rounded-md border px-2.5 py-2 font-mono text-[12.5px]">
                                whsec_••••••••••••
                                {endpoint.secret_hint.replace('…', '')}
                            </code>
                            <p className="text-muted-foreground text-[12px]">
                                {endpoint.secret_rotated_at
                                    ? `Gerado em ${formatDateTime(endpoint.secret_rotated_at)}.`
                                    : null}{' '}
                                O segredo completo só é exibido ao criar ou
                                rotacionar.
                            </p>
                            {endpoint.previous_secret_expires_at && (
                                <div className="bg-warning-bg border-warning-border text-warning rounded-lg border px-3 py-2 text-[12.5px]">
                                    O segredo anterior continua válido até{' '}
                                    {formatDateTime(
                                        endpoint.previous_secret_expires_at,
                                    )}
                                    .
                                    <Button
                                        variant="link"
                                        size="xxs"
                                        className="text-warning ml-1 h-auto p-0 underline"
                                        onClick={() =>
                                            post(
                                                expire_previous.url(
                                                    endpoint.id,
                                                ),
                                            )
                                        }
                                    >
                                        Encerrar agora
                                    </Button>
                                </div>
                            )}
                            <Button
                                variant="outline"
                                size="xs"
                                className="self-start"
                                onClick={() => setRotating(true)}
                            >
                                <RotateCw className="size-3.5" />
                                Rotacionar segredo
                            </Button>
                        </div>
                    </IntegrationsCard>
                </div>

                <IntegrationsCard
                    title="Entregas"
                    description={`Histórico dos últimos ${limits.retention_days} dias. Até ${limits.max_attempts} tentativas por entrega.`}
                    actions={
                        <>
                            <Select
                                value={filters.status ?? ALL}
                                onValueChange={(value) =>
                                    applyFilters({
                                        status: value === ALL ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="h-[34px] w-[170px]"
                                    aria-label="Situação"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        Todas as situações
                                    </SelectItem>
                                    {statuses.map((status) => (
                                        <SelectItem
                                            key={status.value}
                                            value={status.value}
                                        >
                                            {status.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.event ?? ALL}
                                onValueChange={(value) =>
                                    applyFilters({
                                        event: value === ALL ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="h-[34px] w-[220px]"
                                    aria-label="Evento"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        Todos os eventos
                                    </SelectItem>
                                    <SelectItem value="webhook.ping">
                                        Evento de teste
                                    </SelectItem>
                                    {catalog.map((event) => (
                                        <SelectItem
                                            key={event.value}
                                            value={event.value}
                                        >
                                            {event.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </>
                    }
                >
                    {deliveries.data.length === 0 ? (
                        <EmptyState
                            icon={Inbox}
                            title={
                                filters.status || filters.event
                                    ? 'Nenhuma entrega com esses filtros'
                                    : 'Nenhuma entrega ainda'
                            }
                            description='Use "Enviar teste" para conferir se o seu servidor recebe e valida a assinatura antes do primeiro evento real.'
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <div className="min-w-[760px]">
                                <div className="text-muted-foreground bg-background border-border grid h-[38px] grid-cols-[minmax(0,1.6fr)_.9fr_.9fr_.7fr_.7fr_1.1fr_150px] items-center gap-3 border-y px-5 text-[12px] font-semibold">
                                    <span>Evento</span>
                                    <span>Documento</span>
                                    <span>Situação</span>
                                    <span>Resposta</span>
                                    <span>Tentativa</span>
                                    <span>Quando</span>
                                    <span />
                                </div>
                                <ul className="divide-border divide-y">
                                    {deliveries.data.map((delivery) => (
                                        <li
                                            key={delivery.id}
                                            className="hover:bg-row-hover grid grid-cols-[minmax(0,1.6fr)_.9fr_.9fr_.7fr_.7fr_1.1fr_150px] items-center gap-3 px-5 py-2.5 text-[13px]"
                                        >
                                            <span className="min-w-0">
                                                <code className="text-primary block truncate font-mono text-[12.5px] font-semibold">
                                                    {delivery.event_type}
                                                </code>
                                                <span className="text-muted-foreground block truncate font-mono text-[11px]">
                                                    {delivery.id}
                                                </span>
                                            </span>
                                            <span className="text-text-secondary text-[12.5px]">
                                                {delivery.is_test
                                                    ? 'Teste'
                                                    : (delivery.envelope
                                                          ?.code ?? '—')}
                                            </span>
                                            <span>
                                                <DeliveryStatusBadge
                                                    status={delivery.status}
                                                    label={
                                                        delivery.status_label
                                                    }
                                                />
                                            </span>
                                            <span>
                                                <HttpStatusBadge
                                                    status={
                                                        delivery.last_response_code
                                                    }
                                                />
                                            </span>
                                            <span className="text-text-secondary tabular text-[12.5px]">
                                                {delivery.attempts}/
                                                {delivery.max_attempts}
                                            </span>
                                            <span
                                                className="text-text-secondary tabular text-[12.5px] whitespace-nowrap"
                                                title={
                                                    delivery.last_error_label ??
                                                    undefined
                                                }
                                            >
                                                {formatRelativeDateTime(
                                                    delivery.last_attempt_at ??
                                                        delivery.created_at,
                                                )}
                                            </span>
                                            <span className="flex justify-end gap-1.5">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="xxs"
                                                    onClick={() =>
                                                        setOpenDelivery(
                                                            delivery.id,
                                                        )
                                                    }
                                                >
                                                    Detalhes
                                                </Button>
                                                {delivery.can_resend && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="xxs"
                                                        onClick={() =>
                                                            post(
                                                                resend.url({
                                                                    webhookEndpoint:
                                                                        endpoint.id,
                                                                    delivery:
                                                                        delivery.id,
                                                                }),
                                                            )
                                                        }
                                                    >
                                                        Reenviar
                                                    </Button>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}
                    <TablePagination
                        paginated={deliveries}
                        entity="entregas"
                        entitySingular="entrega"
                        gender="f"
                        showPerPage={false}
                    />
                </IntegrationsCard>
            </IntegrationsShell>

            <EndpointFormDialog
                key={`${endpoint.id}-${editing ? 'open' : 'closed'}`}
                open={editing}
                onOpenChange={setEditing}
                catalog={catalog}
                endpoint={endpoint}
            />

            <DeliverySheet
                endpointId={endpoint.id}
                deliveryId={openDelivery}
                onOpenChange={(open) => !open && setOpenDelivery(null)}
            />

            <ConfirmDialog
                open={rotating}
                onOpenChange={setRotating}
                title="Rotacionar o segredo?"
                description="Um segredo novo é gerado e exibido uma única vez. Durante a convivência, as entregas levam as duas assinaturas, para você trocar o segredo no seu sistema sem perder eventos."
                confirmLabel="Gerar segredo novo"
                processing={processing}
                onConfirm={doRotate}
            >
                <div className="grid gap-2">
                    <Label htmlFor="rotation-overlap">
                        O segredo anterior continua válido por
                    </Label>
                    <Select value={overlap} onValueChange={setOverlap}>
                        <SelectTrigger id="rotation-overlap" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {overlapOptions.map((hours) => (
                                <SelectItem key={hours} value={String(hours)}>
                                    {hours === 0
                                        ? 'Encerrar agora (segredo vazado)'
                                        : hours < 48
                                          ? `${hours} horas`
                                          : `${hours / 24} dias`}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={removing}
                onOpenChange={setRemoving}
                title="Remover este endpoint?"
                description="As entregas pendentes são canceladas e nenhum evento novo será enviado a ele. O histórico continua visível até a limpeza automática."
                confirmLabel="Remover"
                destructive
                processing={processing}
                onConfirm={doRemove}
            />
        </>
    );
}

WebhookShow.layout = {
    breadcrumbs: [
        { title: 'API e integrações', href: integrationsIndex() },
        { title: 'Webhooks', href: webhooksIndex() },
        // `href` é obrigatório (BreadcrumbItem): sem ele o AppTopbar chamava
        // `toUrl(undefined)` e a página inteira ficava em branco (achado da QA I-2D).
        // O último item é o atual e não vira link; o destino só compõe a chave.
        { title: 'Endpoint', href: webhooksIndex() },
    ],
} satisfies { breadcrumbs: BreadcrumbItem[] };
