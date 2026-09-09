import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Download,
    FileText,
    MoreHorizontal,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AvatarInitials, AvatarStack } from '@/components/avatar-initials';
import {
    DataTable,
    TitleCell,
    type DataTableColumn,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { KpiCard, KpiGrid } from '@/components/kpi-card';
import { PageHeader } from '@/components/page-header';
import { ProgressMeter } from '@/components/progress-meter';
import { SegmentedControl } from '@/components/segmented-control';
import { EnvelopeStatusBadge } from '@/components/status/envelope-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import {
    formatBytes,
    formatDayMonth,
    formatDuration,
    formatNumber,
    formatPercent,
    formatProgress,
    formatRelativeDateTime,
    formatTimeAgo,
    plural,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { getTimeZone } from '@/lib/format';
import { dashboard } from '@/routes';
import { exportMethod as dashboardExport } from '@/routes/dashboard';
import {
    create as envelopesCreate,
    download as envelopeDownload,
    index as envelopesIndex,
    show as envelopesShow,
} from '@/routes/envelopes';
import { resend as resendRecipient } from '@/routes/envelopes/recipients';
import { index as recipientsIndex } from '@/routes/recipients';
import { index as billingIndex } from '@/routes/billing';
import type { EnvelopeListItem } from '@/types';

type Range = '30d' | '90d' | '12m';

export interface DashboardProps {
    greeting: { first_name: string; date_label: string; pending_count: number };
    range: Range;
    kpis: {
        sent: {
            value: number;
            delta_pct: number | null;
            previous_value: number;
            previous_label: string;
        };
        pending: { value: number; expiring_48h: number };
        completed: {
            value: number;
            completion_rate_pct: number | null;
            refused_or_expired: number;
        };
        avg_time_to_complete: {
            minutes: number | null;
            delta_minutes: number | null;
        };
    };
    chart: {
        buckets: { date: string; sent: number; completed: number }[];
        totals: { sent: number; completed: number };
        axis_labels: string[];
    };
    pending_recipients: {
        id: string;
        envelope_id: string;
        name: string;
        initials: string;
        envelope_title: string;
        waiting_since: string;
        can_resend: boolean;
    }[];
    pending_recipients_total: number;
    plan_usage: {
        plan_name: string;
        renews_at: string | null;
        envelopes: { used: number; limit: number | null };
        members: { used: number; limit: number | null };
        storage: { used_bytes: number; limit_bytes: number | null };
    };
    recent_envelopes: EnvelopeListItem[];
    recent_total: number;
}

const RANGE_OPTIONS: { value: Range; label: string }[] = [
    { value: '30d', label: '30 dias' },
    { value: '90d', label: '90 dias' },
    { value: '12m', label: '12 meses' },
];

function salutation(now = new Date()): string {
    const hour = Number(
        new Intl.DateTimeFormat('pt-BR', {
            hour: 'numeric',
            hour12: false,
            timeZone: getTimeZone(),
        }).format(now),
    );

    if (hour >= 5 && hour < 12) {
        return 'Bom dia';
    }

    if (hour >= 12 && hour < 18) {
        return 'Boa tarde';
    }

    return 'Boa noite';
}

const RANGE_SUBTITLES: Record<Range, string> = {
    '30d': 'Enviadas × concluídas nos últimos 30 dias',
    '90d': 'Enviadas × concluídas nos últimos 90 dias',
    '12m': 'Enviadas × concluídas nos últimos 12 meses',
};

/** Dashboard (ROUTES §2.4; DESIGN §6.2). */
export default function Dashboard({
    greeting,
    range,
    kpis,
    chart,
    pending_recipients,
    pending_recipients_total,
    plan_usage,
    recent_envelopes,
    recent_total,
}: DashboardProps) {
    const [reloading, setReloading] = useState(false);
    const [resending, setResending] = useState<string | null>(null);

    const changeRange = (next: Range) => {
        if (next === range) {
            return;
        }

        setReloading(true);
        router.get(
            dashboard.url({ query: { range: next } }),
            {},
            {
                only: ['kpis', 'chart', 'range'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setReloading(false),
            },
        );
    };

    const remind = (
        recipient: DashboardProps['pending_recipients'][number],
    ) => {
        setResending(recipient.id);
        router.post(
            resendRecipient({
                envelope: recipient.envelope_id,
                recipient: recipient.id,
            }).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`Convite reenviado para ${recipient.name}`),
                onError: () =>
                    toast.error('Aguarde alguns minutos antes de reenviar'),
                onFinish: () => setResending(null),
            },
        );
    };

    const maxSent = Math.max(1, ...chart.buckets.map((b) => b.sent));

    const columns: DataTableColumn<EnvelopeListItem>[] = [
        {
            key: 'title',
            header: 'Documento',
            width: 'minmax(0,2.4fr)',
            cell: (row) => (
                <TitleCell
                    icon={<FileText className="size-[17px]" />}
                    title={row.title}
                    href={envelopesShow(row.id).url}
                    onClick={() => router.visit(envelopesShow(row.id).url)}
                    meta={[
                        row.display_code,
                        row.folder?.name,
                        row.document?.pages
                            ? `PDF · ${plural(row.document.pages, 'pág')}`
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />
            ),
        },
        {
            key: 'recipients',
            header: 'Signatários',
            width: '1.3fr',
            cell: (row) => (
                <AvatarStack
                    items={row.recipients}
                    progress={formatProgress(
                        row.signed_count,
                        row.recipients_count,
                    )}
                />
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '1fr',
            cell: (row) => (
                <EnvelopeStatusBadge
                    status={row.status}
                    signedCount={row.signed_count}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'updated',
            header: 'Atualizado',
            width: '1fr',
            cell: (row) => (
                <span className="text-text-secondary tabular text-[13px] whitespace-nowrap">
                    {formatRelativeDateTime(row.updated_at)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            width: '48px',
            align: 'right',
            cell: (row) => (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon-xs"
                            aria-label="Ações"
                        >
                            <MoreHorizontal className="text-muted-foreground size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem asChild>
                            <Link href={envelopesShow(row.id)}>Abrir</Link>
                        </DropdownMenuItem>
                        {row.can.download_signed && (
                            <DropdownMenuItem asChild>
                                <a
                                    href={
                                        envelopeDownload({
                                            envelope: row.id,
                                            type: 'signed',
                                        }).url
                                    }
                                >
                                    <Download className="size-3.5" />
                                    Baixar PDF assinado
                                </a>
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
        },
    ];

    return (
        <>
            <Head title="Dashboard" />

            <PageHeader
                eyebrow="Visão geral"
                title={
                    greeting.first_name
                        ? `${salutation()}, ${greeting.first_name}`
                        : salutation()
                }
                subtitle={`${greeting.date_label} · ${plural(greeting.pending_count, 'documento aguarda assinatura', 'documentos aguardam assinatura')}`}
                actions={
                    <>
                        <Button asChild variant="outline">
                            <a href={dashboardExport.url({ query: { range } })}>
                                <Download className="size-[15px]" />
                                Exportar
                            </a>
                        </Button>
                        <Button asChild>
                            <Link href={envelopesCreate()}>
                                <Plus
                                    className="size-[15px]"
                                    strokeWidth={2.5}
                                />
                                Nova solicitação
                            </Link>
                        </Button>
                    </>
                }
            />

            <KpiGrid>
                {reloading ? (
                    Array.from({ length: 4 }, (_, i) => (
                        <div
                            key={i}
                            className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5"
                        >
                            <Skeleton className="h-3.5 w-2/3" />
                            <Skeleton className="h-8 w-1/3" />
                            <Skeleton className="h-3 w-1/2" />
                        </div>
                    ))
                ) : (
                    <>
                        <KpiCard
                            label="Documentos enviados"
                            value={formatNumber(kpis.sent.value)}
                            delta={
                                kpis.sent.delta_pct !== null
                                    ? {
                                          label: formatPercent(
                                              kpis.sent.delta_pct,
                                          ),
                                          tone:
                                              kpis.sent.delta_pct >= 0
                                                  ? 'success'
                                                  : 'danger',
                                          direction:
                                              kpis.sent.delta_pct >= 0
                                                  ? 'up'
                                                  : 'down',
                                      }
                                    : null
                            }
                            caption={`vs. ${formatNumber(kpis.sent.previous_value)} ${kpis.sent.previous_label}`}
                        />
                        <KpiCard
                            label="Aguardando assinatura"
                            value={formatNumber(kpis.pending.value)}
                            delta={
                                kpis.pending.expiring_48h > 0
                                    ? {
                                          label: `${kpis.pending.expiring_48h} ${kpis.pending.expiring_48h === 1 ? 'vence' : 'vencem'} em 48h`,
                                          tone: 'warning',
                                      }
                                    : null
                            }
                            caption="Reenvie convites pelo detalhe do documento"
                        />
                        <KpiCard
                            label="Concluídos"
                            value={formatNumber(kpis.completed.value)}
                            delta={
                                kpis.completed.completion_rate_pct !== null
                                    ? {
                                          label: `${formatPercent(kpis.completed.completion_rate_pct)} de conclusão`,
                                          tone: 'success',
                                      }
                                    : null
                            }
                            caption={`${formatNumber(kpis.completed.refused_or_expired)} recusados ou expirados`}
                        />
                        <KpiCard
                            label="Tempo médio para assinar"
                            // Mesmo formatador da tela Assinaturas: contratos levam dias,
                            // e o número cru em minutos ("1.698") é ilegível e ambíguo em
                            // pt-BR, onde o ponto separa milhar mas é lido como decimal.
                            value={formatDuration(
                                kpis.avg_time_to_complete.minutes,
                            )}
                            delta={
                                kpis.avg_time_to_complete.delta_minutes !== null
                                    ? {
                                          label: `${kpis.avg_time_to_complete.delta_minutes > 0 ? '+' : '−'}${formatDuration(Math.abs(kpis.avg_time_to_complete.delta_minutes))}`,
                                          tone:
                                              kpis.avg_time_to_complete
                                                  .delta_minutes <= 0
                                                  ? 'success'
                                                  : 'warning',
                                      }
                                    : null
                            }
                            caption="Do envio à última assinatura"
                        />
                    </>
                )}
            </KpiGrid>

            <div className="flex flex-wrap items-start gap-4">
                <div className="border-border bg-card shadow-card min-w-0 flex-[2_1_380px] rounded-xl border">
                    <div className="flex flex-wrap items-start justify-between gap-4 px-5 pt-5">
                        <div>
                            <div className="text-[15px] font-semibold">
                                Assinaturas por dia
                            </div>
                            <div className="text-muted-foreground mt-1 text-[13px]">
                                {RANGE_SUBTITLES[range]}
                            </div>
                        </div>
                        <SegmentedControl
                            value={range}
                            onChange={changeRange}
                            options={RANGE_OPTIONS}
                            ariaLabel="Período"
                        />
                    </div>
                    <div className="text-text-secondary flex gap-[18px] px-5 pt-3.5 text-[12.5px]">
                        <span className="inline-flex items-center gap-1.5">
                            <span className="bg-chart-1 size-2.5 rounded-[3px]" />
                            Concluídas{' '}
                            <b className="text-foreground tabular">
                                {formatNumber(chart.totals.completed)}
                            </b>
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <span className="bg-chart-2 size-2.5 rounded-[3px]" />
                            Enviadas{' '}
                            <b className="text-foreground tabular">
                                {formatNumber(chart.totals.sent)}
                            </b>
                        </span>
                    </div>
                    <div
                        className={cn(
                            'flex h-[190px] items-end gap-1 px-5 pt-4',
                            reloading && 'opacity-50',
                        )}
                    >
                        {chart.buckets.length === 0 ? (
                            <p className="text-muted-foreground w-full self-center text-center text-[13px]">
                                Sem dados no período.
                            </p>
                        ) : (
                            chart.buckets.map((bucket) => {
                                const sentPct = Math.round(
                                    (bucket.sent / maxSent) * 100,
                                );
                                const donePct =
                                    bucket.sent > 0
                                        ? Math.round(
                                              (bucket.completed / bucket.sent) *
                                                  100,
                                          )
                                        : 0;

                                return (
                                    <div
                                        key={bucket.date}
                                        className="flex h-full flex-1 flex-col justify-end"
                                        title={`${formatDayMonth(bucket.date)} · ${bucket.sent} enviados · ${bucket.completed} concluídos`}
                                    >
                                        <div
                                            className="bg-chart-2 relative rounded-t-[3px]"
                                            style={{
                                                height: `${Math.max(sentPct, bucket.sent > 0 ? 3 : 0)}%`,
                                            }}
                                        >
                                            <div
                                                className="bg-chart-1 absolute inset-x-0 bottom-0 rounded-t-[3px]"
                                                style={{
                                                    height: `${donePct}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </div>
                    <div className="border-muted text-muted-foreground flex justify-between border-t px-5 pt-2 pb-4 text-[11.5px]">
                        {chart.axis_labels.map((label, i) => (
                            <span key={`${label}-${i}`}>{label}</span>
                        ))}
                    </div>
                </div>

                <div className="flex min-w-0 flex-[1_1_280px] flex-col gap-4">
                    <div className="border-border bg-card shadow-card flex flex-col rounded-xl border">
                        <div className="flex items-center justify-between gap-3 px-5 pt-[18px] pb-3">
                            <div>
                                <div className="text-[15px] font-semibold">
                                    Pendências
                                </div>
                                <div className="text-muted-foreground mt-1 text-[13px]">
                                    Signatários sem resposta
                                </div>
                            </div>
                            {pending_recipients_total > 0 && (
                                <Badge
                                    variant="warning"
                                    className="text-[12px] font-bold"
                                >
                                    {formatNumber(pending_recipients_total)}
                                </Badge>
                            )}
                        </div>
                        {pending_recipients.length === 0 ? (
                            <p className="border-muted text-muted-foreground border-t px-5 py-6 text-center text-[13px]">
                                Nenhum signatário pendente 🎉
                            </p>
                        ) : (
                            pending_recipients.map((recipient, index) => (
                                <div
                                    key={recipient.id}
                                    className="border-muted flex items-center gap-3 border-t px-5 py-2.5"
                                >
                                    <AvatarInitials
                                        initials={recipient.initials}
                                        index={index}
                                        size="sm"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[13.5px] font-semibold">
                                            {recipient.name}
                                        </span>
                                        <span className="text-muted-foreground block truncate text-[12px]">
                                            {recipient.envelope_title} ·{' '}
                                            {formatTimeAgo(
                                                recipient.waiting_since,
                                            )}
                                        </span>
                                    </span>
                                    <Button
                                        variant="outline-sm"
                                        size="xxs"
                                        disabled={
                                            !recipient.can_resend ||
                                            resending === recipient.id
                                        }
                                        onClick={() => remind(recipient)}
                                        title={
                                            recipient.can_resend
                                                ? 'Reenviar convite'
                                                : 'Reenviado há menos de 10 minutos'
                                        }
                                    >
                                        Lembrar
                                    </Button>
                                </div>
                            ))
                        )}
                        <Link
                            href={recipientsIndex({
                                query: { status: 'pending' },
                            })}
                            className="border-muted text-primary hover:text-primary-hover flex items-center justify-center gap-1.5 border-t p-3 text-[13px] font-semibold"
                        >
                            Ver todas as pendências
                            <ArrowRight className="size-3.5" />
                        </Link>
                    </div>

                    <div className="border-border bg-card shadow-card flex flex-col gap-3.5 rounded-xl border px-5 py-[18px]">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <div className="text-[15px] font-semibold">
                                    Uso do plano
                                </div>
                                <div className="text-muted-foreground mt-1 text-[13px]">
                                    {plan_usage.plan_name}
                                    {plan_usage.renews_at &&
                                        ` · renova em ${formatDayMonth(plan_usage.renews_at)}`}
                                </div>
                            </div>
                            <Link
                                href={billingIndex()}
                                className="text-primary hover:text-primary-hover text-[13px] font-semibold"
                            >
                                Gerenciar
                            </Link>
                        </div>
                        <ProgressMeter
                            label="Documentos"
                            used={plan_usage.envelopes.used}
                            limit={plan_usage.envelopes.limit}
                        />
                        <ProgressMeter
                            label="Usuários"
                            used={plan_usage.members.used}
                            limit={plan_usage.members.limit}
                        />
                        <ProgressMeter
                            label="Armazenamento"
                            used={plan_usage.storage.used_bytes}
                            limit={plan_usage.storage.limit_bytes}
                            usedLabel={formatBytes(
                                plan_usage.storage.used_bytes,
                            )}
                            limitLabel={
                                plan_usage.storage.limit_bytes
                                    ? formatBytes(
                                          plan_usage.storage.limit_bytes,
                                      )
                                    : undefined
                            }
                        />
                    </div>
                </div>
            </div>

            <div className="border-border bg-card shadow-card rounded-xl border">
                <div className="flex items-center justify-between gap-3 px-5 pt-[18px] pb-3">
                    <div>
                        <div className="text-[15px] font-semibold">
                            Documentos recentes
                        </div>
                        <div className="text-muted-foreground mt-1 text-[13px]">
                            Atualizados nos últimos 7 dias
                        </div>
                    </div>
                    <Button asChild variant="outline" size="xs">
                        <Link href={envelopesIndex()}>Ver todos</Link>
                    </Button>
                </div>
                <DataTable
                    columns={columns}
                    rows={recent_envelopes}
                    rowKey={(row) => row.id}
                    minWidth={640}
                    empty={
                        <EmptyState
                            variant="inline"
                            title="Você ainda não enviou documentos"
                            action={
                                <Button asChild>
                                    <Link href={envelopesCreate()}>
                                        <Plus
                                            className="size-[15px]"
                                            strokeWidth={2.5}
                                        />
                                        Nova solicitação
                                    </Link>
                                </Button>
                            }
                        />
                    }
                />
                {recent_envelopes.length > 0 && (
                    <div className="text-muted-foreground flex items-center justify-between px-5 py-3 text-[12.5px]">
                        <span className="tabular">
                            Mostrando {recent_envelopes.length} de{' '}
                            {formatNumber(recent_total)} documentos
                        </span>
                        <Link
                            href={envelopesIndex()}
                            className="text-primary hover:text-primary-hover font-semibold"
                        >
                            Abrir lista completa
                        </Link>
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
