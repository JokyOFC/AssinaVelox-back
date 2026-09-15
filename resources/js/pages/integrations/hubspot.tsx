import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Info } from 'lucide-react';
import { useState } from 'react';
import { CopyButton } from '@/components/copy-button';
import { InlineCode } from '@/components/integrations/code-block';
import type {
    HubSpotPageProps,
    HubSpotStatus,
} from '@/components/integrations/cloud/types';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateMedium } from '@/lib/format';
import { index as integrationsIndex } from '@/routes/integrations';
import {
    connect as hubspotConnect,
    disconnect as hubspotDisconnect,
    show as hubspotShow,
} from '@/routes/integrations/hubspot';

const STATUS_BADGE: Record<
    HubSpotStatus,
    { label: string; variant: 'success' | 'neutral' | 'warning' | 'danger' }
> = {
    awaiting_app: {
        label: 'Aguardando app registrado pelo proprietário',
        variant: 'neutral',
    },
    disconnected: { label: 'Não conectado', variant: 'warning' },
    connected: { label: 'Conectado', variant: 'success' },
    error: { label: 'Conexão precisa ser refeita', variant: 'danger' },
};

const SYNC_LABELS: Record<string, string> = {
    not_applicable: '—',
    pending: 'Atualizando no HubSpot',
    synced: 'Atualizado no HubSpot',
    failed: 'Falhou ao atualizar no HubSpot',
};

/** Motivo da falha de uma execução, em PT-BR (o código fica só na trilha). */
const ERROR_LABELS: Record<string, string> = {
    template_not_found: 'modelo não encontrado',
    template_required: 'faltou o identificador do modelo',
    too_many_roles: 'o modelo tem mais papéis do que a ação aceita',
    invalid_input: 'dados da ação incompletos ou inválidos',
    connector_user_unavailable: 'quem conectou a conta não tem mais acesso',
    reconnect_required: 'a conexão com o HubSpot precisa ser refeita',
    refresh_rejected: 'a conexão com o HubSpot precisa ser refeita',
    not_connected: 'a conta do HubSpot não está mais conectada',
    internal_error: 'erro interno; tente de novo',
};

function Card({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
            <div>
                <h2 className="text-[15px] font-semibold">{title}</h2>
                {description && (
                    <p className="text-muted-foreground mt-0.5 text-[12.5px]">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

/**
 * Integrações → HubSpot (Fase 3 §3.9, G-CONN — docs/fase-3/conectores.md §5). Conectar e
 * desconectar a conta, configurar a ação de workflow e acompanhar as execuções. Sem o app
 * registrado pelo proprietário, a tela diz isso, nada é conectado e a ação, os modelos e as
 * execuções ficam recolhidos (nada é descrito como se já funcionasse).
 */
export default function HubSpotPage({
    status,
    connection,
    action,
    templates,
    executions,
}: HubSpotPageProps) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const badge = STATUS_BADGE[status];
    const awaiting = status === 'awaiting_app';

    const connect = (): void => {
        setProcessing(true);
        router.post(
            hubspotConnect.url(),
            {},
            { onFinish: () => setProcessing(false) },
        );
    };

    const disconnect = (): void => {
        setProcessing(true);
        router.delete(hubspotDisconnect.url(), {
            onFinish: () => {
                setProcessing(false);
                setConfirming(false);
            },
        });
    };

    return (
        <>
            <Head title="HubSpot" />
            <PageHeader
                title="HubSpot"
                subtitle="Envie para assinatura a partir de workflows e acompanhe o estado no negócio ou no contato."
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={integrationsIndex.url()}>
                            <ArrowLeft className="size-[15px]" />
                            API e integrações
                        </Link>
                    </Button>
                }
            />

            <div className="flex flex-col gap-5">
                <Card title="Conexão">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={badge.variant}>{badge.label}</Badge>
                    </div>

                    {awaiting && (
                        <p className="text-muted-foreground text-[13px] leading-[1.5]">
                            Ainda não disponível: a conexão depende do app do
                            HubSpot registrado pelo proprietário da plataforma.
                            Nenhuma conta pode ser conectada até lá.
                        </p>
                    )}

                    {connection && (
                        <dl className="grid gap-x-6 gap-y-1.5 text-[13px] sm:grid-cols-[max-content_1fr]">
                            <dt className="text-muted-foreground">
                                Conta do HubSpot (portal)
                            </dt>
                            <dd className="tabular">{connection.portal_id}</dd>
                            <dt className="text-muted-foreground">
                                Conectada em
                            </dt>
                            <dd>
                                {connection.connected_at
                                    ? formatDateMedium(connection.connected_at)
                                    : '—'}
                                {connection.connected_by &&
                                    ` por ${connection.connected_by}`}
                            </dd>
                            <dt className="text-muted-foreground">
                                Permissões
                            </dt>
                            <dd className="break-words">
                                {connection.scopes.join(', ') || '—'}
                            </dd>
                        </dl>
                    )}

                    <div className="flex flex-wrap gap-2">
                        {(status === 'disconnected' ||
                            status === 'error' ||
                            awaiting) && (
                            <Button
                                size="sm"
                                disabled={awaiting || processing}
                                onClick={connect}
                            >
                                {status === 'error'
                                    ? 'Conectar de novo'
                                    : 'Conectar ao HubSpot'}
                            </Button>
                        )}
                        {connection &&
                            (confirming ? (
                                <>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        disabled={processing}
                                        onClick={disconnect}
                                    >
                                        Confirmar desconexão
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setConfirming(false)}
                                    >
                                        Manter conectado
                                    </Button>
                                </>
                            ) : (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setConfirming(true)}
                                >
                                    Desconectar
                                </Button>
                            ))}
                    </div>
                    {confirming && (
                        <p className="text-muted-foreground text-[12.5px]">
                            O acesso é revogado no HubSpot e as chaves são
                            apagadas. Os workflows que usam a ação param de
                            criar documentos.
                        </p>
                    )}
                </Card>

                {awaiting ? (
                    <Card
                        title="Ação de workflow, modelos e execuções"
                        description="Disponível depois que o app for registrado pelo proprietário da plataforma."
                    >
                        <p className="text-muted-foreground text-[13px] leading-[1.5]">
                            Quando o app estiver registrado e a conta conectada,
                            esta tela mostra o endereço da ação “Enviar para
                            assinatura”, os modelos que ela pode usar e as
                            execuções recebidas.
                        </p>
                    </Card>
                ) : (
                    <>
                        <Card
                            title="Ação de workflow “Enviar para assinatura”"
                            description="Configurada no app do HubSpot. Cada execução cria um documento a partir de um modelo e o envia, se estiver pronto."
                        >
                            <div className="flex flex-wrap items-center gap-2 text-[13px]">
                                <span className="text-muted-foreground">
                                    Endereço da ação:
                                </span>
                                <InlineCode>{action.url}</InlineCode>
                                <CopyButton value={action.url} />
                            </div>
                            <ul className="text-text-secondary list-disc space-y-1 pl-5 text-[12.5px] leading-[1.5]">
                                <li>
                                    <InlineCode>template_id</InlineCode>:
                                    identificador do modelo (lista abaixo).
                                </li>
                                <li>
                                    <InlineCode>participant_1_name</InlineCode>{' '}
                                    e{' '}
                                    <InlineCode>participant_1_email</InlineCode>
                                    : um par por papel do modelo, na ordem dos
                                    papéis (até {action.max_participants}).
                                </li>
                                <li>
                                    <InlineCode>var_chave</InlineCode>: valor de
                                    cada variável do modelo.{' '}
                                    <InlineCode>title</InlineCode> é opcional.
                                </li>
                                <li>
                                    Resposta:{' '}
                                    <InlineCode>
                                        assinavelox_envelope_id
                                    </InlineCode>
                                    ,{' '}
                                    <InlineCode>assinavelox_status</InlineCode>{' '}
                                    e{' '}
                                    <InlineCode>assinavelox_message</InlineCode>
                                    .
                                </li>
                                <li>
                                    Quando o documento muda de estado, a
                                    propriedade{' '}
                                    <InlineCode>
                                        {action.status_property}
                                    </InlineCode>{' '}
                                    do negócio ou do contato recebe o novo
                                    estado: enviado (
                                    <InlineCode>sent</InlineCode>), concluído (
                                    <InlineCode>completed</InlineCode>),
                                    recusado (<InlineCode>refused</InlineCode>
                                    ), cancelado (
                                    <InlineCode>canceled</InlineCode>) ou
                                    expirado (<InlineCode>expired</InlineCode>
                                    ). A propriedade precisa existir no HubSpot.
                                </li>
                            </ul>
                            <p className="text-muted-foreground flex items-start gap-2 text-[12.5px] leading-[1.5]">
                                <Info className="mt-0.5 size-3.5 shrink-0" />
                                Cada requisição do HubSpot tem a assinatura
                                digital dele conferida, e repetir a mesma
                                execução devolve a mesma resposta, sem criar
                                outro documento.
                            </p>
                        </Card>

                        <Card
                            title="Modelos disponíveis"
                            description="Use o identificador no campo template_id da ação."
                        >
                            {templates.length === 0 ? (
                                <p className="text-muted-foreground text-[13px]">
                                    Nenhum modelo ativo nesta organização.
                                </p>
                            ) : (
                                <ul className="divide-border divide-y">
                                    {templates.map((template) => (
                                        <li
                                            key={template.id}
                                            className="flex flex-wrap items-center justify-between gap-2 py-2 text-[13px]"
                                        >
                                            <span className="min-w-0 truncate">
                                                {template.name}
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <InlineCode>
                                                    {template.id}
                                                </InlineCode>
                                                <CopyButton
                                                    value={template.id}
                                                />
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>

                        <Card title="Execuções recentes">
                            {executions.length === 0 ? (
                                <p className="text-muted-foreground text-[13px]">
                                    Nenhuma execução recebida.
                                </p>
                            ) : (
                                <ul className="divide-border divide-y">
                                    {executions.map((execution) => (
                                        <li
                                            key={execution.id}
                                            className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[13px]"
                                        >
                                            <span className="flex min-w-0 flex-col">
                                                {execution.envelope ? (
                                                    <Link
                                                        href={
                                                            execution.envelope
                                                                .url
                                                        }
                                                        className="text-primary truncate font-semibold"
                                                    >
                                                        {
                                                            execution.envelope
                                                                .title
                                                        }
                                                    </Link>
                                                ) : (
                                                    <span className="truncate font-semibold">
                                                        Sem documento criado
                                                    </span>
                                                )}
                                                <span className="text-muted-foreground text-[12px]">
                                                    {execution.status_label}
                                                    {execution.error_code &&
                                                        `: ${ERROR_LABELS[execution.error_code] ?? 'motivo registrado na trilha'}`}
                                                    {' · '}
                                                    {SYNC_LABELS[
                                                        execution.sync_status
                                                    ] ?? execution.sync_status}
                                                </span>
                                            </span>
                                            <span className="text-muted-foreground tabular text-[12px]">
                                                {execution.created_at
                                                    ? formatDateMedium(
                                                          execution.created_at,
                                                      )
                                                    : ''}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </>
                )}

                <p className="text-muted-foreground flex items-start gap-2 text-[12.5px] leading-[1.5]">
                    <Info className="mt-0.5 size-3.5 shrink-0" />
                    Cartão do AssinaVelox dentro do registro do HubSpot: ainda
                    não disponível. Depende de um recurso para apps públicos que
                    o HubSpot não confirmou como disponível para todos.
                </p>
            </div>
        </>
    );
}

HubSpotPage.layout = {
    breadcrumbs: [
        { title: 'API e integrações', href: integrationsIndex() },
        { title: 'HubSpot', href: hubspotShow() },
    ],
};
