import { Head, router } from '@inertiajs/react';
import { FlaskConical, RefreshCw, Scale } from 'lucide-react';
import { useState } from 'react';
import {
    AdminBillingPanels,
    type FiscalSummary,
    type MethodFamily,
    type ReconciliationSummary,
    type RecurringSummary,
} from '@/components/billing/admin-billing-panels';
import { BillingCallout } from '@/components/billing/billing-callout';
import {
    RefundDialog,
    type RefundTarget,
} from '@/components/billing/refund-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { useConfirmsPassword } from '@/components/confirms-password';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { FilterBar, SearchInput } from '@/components/filter-bar';
import { KpiCard, KpiGrid } from '@/components/kpi-card';
import { PageHeader } from '@/components/page-header';
import { UnderlineTabs } from '@/components/segmented-control';
import { PaymentStatusBadge } from '@/components/status/payment-status-badge';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    formatCurrency,
    formatDateMedium,
    formatDateTime,
    formatNumber,
} from '@/lib/format';
import { index as adminBilling, reconcile } from '@/routes/admin/billing';
import { resolve as resolveDivergence } from '@/routes/admin/billing/divergences';
import { refresh as refreshMethods } from '@/routes/admin/billing/methods';
import {
    cancel as cancelPayment,
    refund as refundPayment,
    resync as resyncPayment,
} from '@/routes/admin/billing/payments';
import type { Paginated } from '@/types';
import type { PaymentStatus } from '@/types/enums';

type Tab = 'payments' | 'refunds' | 'chargebacks' | 'past_due' | 'divergences';
type OrganizationRef = { id: string; name: string } | null;

interface PaymentRow {
    id: string;
    organization: OrganizationRef;
    plan: string;
    amount_cents: number;
    refunded_cents: number;
    refundable_cents: number;
    currency: string;
    status: PaymentStatus;
    status_label: string;
    status_detail: string | null;
    method_label: string | null;
    paid_at: string | null;
    created_at: string | null;
    expires_at: string | null;
    environment: string;
    is_sandbox: boolean;
    provider: string;
    provider_payment_id: string | null;
    can: { refund: boolean; cancel: boolean; resync: boolean };
}

interface RefundRow {
    id: string;
    payment_id: string | null;
    provider_payment_id: string | null;
    organization: OrganizationRef;
    amount_cents: number;
    currency: string;
    kind: 'total' | 'partial';
    status: string;
    status_label: string;
    initiator: 'platform_admin' | 'owner';
    requested_by: string | null;
    reason: string;
    requested_at: string;
    confirmed_at: string | null;
}

interface ChargebackRow {
    id: string;
    provider_chargeback_id: string;
    payment_id: string | null;
    payment_status_detail: string | null;
    organization: OrganizationRef;
    amount_cents: number;
    currency: string;
    reason: string | null;
    outcome_label: string;
    documentation_status: string | null;
    documentation_deadline_at: string | null;
    received_at: string;
}

interface PastDueRow {
    id: string;
    organization: OrganizationRef;
    plan: string;
    status_label: string;
    current_period_end: string | null;
    days_overdue: number;
}

interface DivergenceRow {
    id: number;
    run_id: string;
    divergence: string;
    divergence_label: string;
    organization: OrganizationRef;
    payment_id: string | null;
    provider_payment_id: string | null;
    local_status: string | null;
    provider_status: string | null;
    local_amount_cents: number | null;
    provider_amount_cents: number | null;
    local_currency: string | null;
    provider_currency: string | null;
    note: string | null;
    detected_at: string | null;
    resolved_at: string | null;
    resolution_note: string | null;
}

export interface AdminBillingProps {
    filters: {
        tab: Tab;
        from: string;
        to: string;
        environment: 'all' | 'sandbox' | 'production';
        status: string | null;
        q: string;
        divergences: 'open' | 'all';
    };
    gateway: {
        name: string;
        is_fake: boolean;
        environment: string;
        configured: boolean;
    };
    revenue: {
        currency: string;
        approved_count: number;
        gross_cents: number;
        refunded_cents: number;
        /** Contestações perdidas (charged_back sem cobertura), já descontadas do líquido. */
        charged_back_cents: number;
        net_cents: number;
    }[];
    counters: {
        pending_payments: number;
        open_chargebacks: number;
        past_due: number;
        open_divergences: number;
        open_refunds: number;
    };
    rows: Paginated<unknown>;
    reconciliation: ReconciliationSummary | null;
    methods: {
        families: MethodFamily[];
        checked_at: string | null;
        offline_expiration_hours: number;
    };
    refund_policy: {
        owner_can_request: boolean;
        owner_window_days: number;
        max_age_days: number;
    };
    recurring: RecurringSummary;
    fiscal: FiscalSummary;
}

const ENVIRONMENT_LABEL: Record<string, string> = {
    sandbox: 'Sandbox',
    production: 'Produção',
};

function OrganizationCell({ organization }: { organization: OrganizationRef }) {
    return (
        <span className="truncate font-medium">
            {organization?.name ?? '—'}
        </span>
    );
}

function Money({
    cents,
    currency,
}: {
    cents: number | null;
    currency: string | null;
}) {
    return (
        <span className="tabular font-semibold">
            {cents === null ? '—' : formatCurrency(cents, currency ?? 'BRL')}
        </span>
    );
}

/**
 * Painel interno › Planos e faturamento (Fase 2, onda D — flag
 * `extended_payments`). Receita por moeda, pagamentos, estornos, contestações,
 * inadimplência e divergências de conciliação. Somente leitura e sem acesso a
 * documentos; estornar e cancelar pedem senha confirmada.
 */
export default function AdminBilling(props: AdminBillingProps) {
    const { filters, gateway, revenue, counters, rows } = props;
    const [refundTarget, setRefundTarget] = useState<RefundTarget | null>(null);
    const [cancelTarget, setCancelTarget] = useState<PaymentRow | null>(null);
    const [resolveTarget, setResolveTarget] = useState<DivergenceRow | null>(
        null,
    );
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const password = useConfirmsPassword({
        description:
            'Estornar e cancelar pagamentos são ações protegidas. Confirme sua senha para continuar.',
    });

    const apply = (next: Partial<AdminBillingProps['filters']>) => {
        router.get(
            adminBilling.url({
                query: { ...filters, ...next, page: undefined },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const post = (
        url: string,
        data: Record<string, string | number> = {},
        done?: () => void,
    ) => {
        setBusy(true);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: done,
            onFinish: () => setBusy(false),
        });
    };

    const paymentColumns: DataTableColumn<PaymentRow>[] = [
        {
            key: 'date',
            header: 'Data',
            width: '0.9fr',
            cell: (row) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {formatDateMedium(row.paid_at ?? row.created_at)}
                </span>
            ),
        },
        {
            key: 'organization',
            header: 'Cliente',
            width: 'minmax(0,1.6fr)',
            cell: (row) => (
                <span className="flex min-w-0 flex-col">
                    <OrganizationCell organization={row.organization} />
                    <span className="text-muted-foreground truncate text-[12px]">
                        Plano {row.plan}
                        {row.method_label ? ` · ${row.method_label}` : ''}
                    </span>
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Valor',
            width: '1fr',
            cell: (row) => (
                <span className="flex flex-col">
                    <Money cents={row.amount_cents} currency={row.currency} />
                    {row.refunded_cents > 0 && (
                        <span className="text-info tabular text-[12px]">
                            Estornado{' '}
                            {formatCurrency(row.refunded_cents, row.currency)}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '1fr',
            cell: (row) => (
                <span className="flex flex-wrap items-center gap-1">
                    <PaymentStatusBadge
                        status={row.status}
                        label={row.status_label}
                    />
                    {row.is_sandbox && <Badge variant="phase">Sandbox</Badge>}
                </span>
            ),
        },
        {
            key: 'provider',
            header: 'Mercado Pago',
            width: '1fr',
            cell: (row) => (
                <span className="text-muted-foreground tabular truncate text-[12px]">
                    {row.provider_payment_id ?? 'sem pagamento'}
                    {row.provider === 'fake' ? ' (dublê)' : ''}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Ações',
            width: '220px',
            align: 'right',
            cell: (row) => (
                <span className="flex justify-end gap-1.5">
                    {row.can.resync && (
                        <Button
                            variant="outline-sm"
                            size="xxs"
                            disabled={busy}
                            onClick={() => post(resyncPayment.url(row.id))}
                        >
                            Reconsultar
                        </Button>
                    )}
                    {row.can.cancel && (
                        <Button
                            variant="outline-sm"
                            size="xxs"
                            onClick={() => setCancelTarget(row)}
                        >
                            Cancelar
                        </Button>
                    )}
                    {row.can.refund && (
                        <Button
                            variant="outline-sm"
                            size="xxs"
                            onClick={() =>
                                setRefundTarget({
                                    id: row.id,
                                    label: `${row.organization?.name ?? 'Cliente'} — plano ${row.plan}`,
                                    refundable_cents: row.refundable_cents,
                                    currency: row.currency,
                                    fully_refundable: row.refunded_cents === 0,
                                })
                            }
                        >
                            Estornar
                        </Button>
                    )}
                </span>
            ),
        },
    ];

    const refundColumns: DataTableColumn<RefundRow>[] = [
        {
            key: 'date',
            header: 'Pedido em',
            width: '1fr',
            cell: (row) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {formatDateTime(row.requested_at)}
                </span>
            ),
        },
        {
            key: 'organization',
            header: 'Cliente',
            width: 'minmax(0,1.4fr)',
            cell: (row) => <OrganizationCell organization={row.organization} />,
        },
        {
            key: 'amount',
            header: 'Valor',
            width: '1fr',
            cell: (row) => (
                <span className="flex flex-col">
                    <Money cents={row.amount_cents} currency={row.currency} />
                    <span className="text-muted-foreground text-[12px]">
                        {row.kind === 'total' ? 'Integral' : 'Parcial'}
                    </span>
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '1.2fr',
            cell: (row) => (
                <Badge
                    variant={
                        row.status === 'approved'
                            ? 'success'
                            : row.status === 'failed' ||
                                row.status === 'rejected'
                              ? 'danger'
                              : 'warning'
                    }
                >
                    {row.status_label}
                </Badge>
            ),
        },
        {
            key: 'who',
            header: 'Pedido por',
            width: 'minmax(0,1.6fr)',
            cell: (row) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate text-[13px]">
                        {row.requested_by ?? '—'}{' '}
                        <span className="text-muted-foreground">
                            (
                            {row.initiator === 'owner'
                                ? 'proprietário'
                                : 'equipe'}
                            )
                        </span>
                    </span>
                    <span className="text-muted-foreground truncate text-[12px]">
                        {row.reason}
                    </span>
                </span>
            ),
        },
    ];

    const chargebackColumns: DataTableColumn<ChargebackRow>[] = [
        {
            key: 'date',
            header: 'Recebida em',
            width: '1fr',
            cell: (row) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {formatDateTime(row.received_at)}
                </span>
            ),
        },
        {
            key: 'organization',
            header: 'Cliente',
            width: 'minmax(0,1.4fr)',
            cell: (row) => <OrganizationCell organization={row.organization} />,
        },
        {
            key: 'amount',
            header: 'Valor',
            width: '1fr',
            cell: (row) => (
                <Money cents={row.amount_cents} currency={row.currency} />
            ),
        },
        {
            key: 'outcome',
            header: 'Andamento',
            width: '1.2fr',
            cell: (row) => (
                <span className="flex flex-col">
                    <span className="text-[13px]">{row.outcome_label}</span>
                    <span className="text-muted-foreground text-[12px]">
                        {row.reason ?? 'motivo não informado'}
                    </span>
                </span>
            ),
        },
        {
            key: 'docs',
            header: 'Documentação',
            width: '1.2fr',
            cell: (row) => (
                <span className="text-muted-foreground text-[12px]">
                    {row.documentation_status ?? '—'}
                    {row.documentation_deadline_at
                        ? ` · prazo ${formatDateMedium(row.documentation_deadline_at)}`
                        : ''}
                </span>
            ),
        },
    ];

    const pastDueColumns: DataTableColumn<PastDueRow>[] = [
        {
            key: 'organization',
            header: 'Cliente',
            width: 'minmax(0,1.6fr)',
            cell: (row) => <OrganizationCell organization={row.organization} />,
        },
        {
            key: 'plan',
            header: 'Plano',
            width: '1fr',
            cell: (row) => <span className="text-[13px]">{row.plan}</span>,
        },
        {
            key: 'end',
            header: 'Ciclo venceu em',
            width: '1fr',
            cell: (row) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {formatDateMedium(row.current_period_end)}
                </span>
            ),
        },
        {
            key: 'days',
            header: 'Em atraso',
            width: '0.8fr',
            align: 'right',
            cell: (row) => (
                <span className="tabular text-danger font-semibold">
                    {formatNumber(row.days_overdue)} d
                </span>
            ),
        },
    ];

    const divergenceColumns: DataTableColumn<DivergenceRow>[] = [
        {
            key: 'kind',
            header: 'Divergência',
            width: '1.2fr',
            cell: (row) => (
                <span className="flex flex-col">
                    <span className="font-medium">{row.divergence_label}</span>
                    <span className="text-muted-foreground text-[12px]">
                        {formatDateTime(row.detected_at)}
                    </span>
                </span>
            ),
        },
        {
            key: 'organization',
            header: 'Cliente',
            width: 'minmax(0,1.3fr)',
            cell: (row) => <OrganizationCell organization={row.organization} />,
        },
        {
            key: 'local',
            header: 'Aqui',
            width: '1fr',
            cell: (row) => (
                <span className="flex flex-col text-[12.5px]">
                    <span>{row.local_status ?? '—'}</span>
                    <Money
                        cents={row.local_amount_cents}
                        currency={row.local_currency}
                    />
                </span>
            ),
        },
        {
            key: 'provider',
            header: 'Mercado Pago',
            width: '1fr',
            cell: (row) => (
                <span className="flex flex-col text-[12.5px]">
                    <span>{row.provider_status ?? '—'}</span>
                    <Money
                        cents={row.provider_amount_cents}
                        currency={row.provider_currency}
                    />
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            width: '200px',
            align: 'right',
            cell: (row) =>
                row.resolved_at ? (
                    <span className="text-muted-foreground text-[12px]">
                        Revisada em {formatDateMedium(row.resolved_at)}
                    </span>
                ) : (
                    <span className="flex justify-end gap-1.5">
                        {row.payment_id && (
                            <Button
                                variant="outline-sm"
                                size="xxs"
                                disabled={busy}
                                onClick={() =>
                                    post(resyncPayment.url(row.payment_id!))
                                }
                            >
                                Reconsultar
                            </Button>
                        )}
                        <Button
                            variant="outline-sm"
                            size="xxs"
                            onClick={() => {
                                setNote('');
                                setResolveTarget(row);
                            }}
                        >
                            Marcar revisada
                        </Button>
                    </span>
                ),
        },
    ];

    const empty = (
        <EmptyState
            variant="inline"
            title="Nada por aqui"
            description="Não há registros para os filtros escolhidos."
        />
    );

    const table = (() => {
        switch (filters.tab) {
            case 'refunds':
                return (
                    <DataTable
                        columns={refundColumns}
                        rows={rows.data as RefundRow[]}
                        rowKey={(row) => row.id}
                        minWidth={820}
                        empty={empty}
                    />
                );
            case 'chargebacks':
                return (
                    <DataTable
                        columns={chargebackColumns}
                        rows={rows.data as ChargebackRow[]}
                        rowKey={(row) => row.id}
                        minWidth={820}
                        empty={empty}
                    />
                );
            case 'past_due':
                return (
                    <DataTable
                        columns={pastDueColumns}
                        rows={rows.data as PastDueRow[]}
                        rowKey={(row) => row.id}
                        minWidth={640}
                        empty={empty}
                    />
                );
            case 'divergences':
                return (
                    <DataTable
                        columns={divergenceColumns}
                        rows={rows.data as DivergenceRow[]}
                        rowKey={(row) => String(row.id)}
                        minWidth={900}
                        empty={empty}
                    />
                );
            default:
                return (
                    <DataTable
                        columns={paymentColumns}
                        rows={rows.data as PaymentRow[]}
                        rowKey={(row) => row.id}
                        minWidth={980}
                        empty={empty}
                    />
                );
        }
    })();

    return (
        <>
            <Head title="Planos e faturamento" />

            <PageHeader
                title="Planos e faturamento"
                subtitle="Receita, pagamentos, estornos, contestações, inadimplência e conciliação com o Mercado Pago. Somente leitura — sem acesso a documentos."
                actions={
                    <>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() => post(refreshMethods.url())}
                        >
                            <RefreshCw aria-hidden className="size-3.5" />
                            Consultar meios ativos
                        </Button>
                        <Button
                            size="sm"
                            disabled={busy}
                            onClick={() => post(reconcile.url())}
                        >
                            <Scale aria-hidden className="size-3.5" />
                            Conciliar agora
                        </Button>
                    </>
                }
            />

            {gateway.is_fake && (
                <BillingCallout
                    tone="neutral"
                    icon={FlaskConical}
                    title="Gateway de desenvolvimento (dublê)"
                >
                    Sem credencial do Mercado Pago, o que aparece aqui vem do
                    dublê identificado: nenhum valor foi cobrado ou devolvido de
                    verdade.
                </BillingCallout>
            )}

            <FilterBar
                trailing={
                    <span className="text-muted-foreground text-[12px]">
                        Ambiente do gateway:{' '}
                        {ENVIRONMENT_LABEL[gateway.environment] ??
                            gateway.environment}
                    </span>
                }
            >
                <div className="flex flex-wrap items-end gap-2">
                    <div className="grid gap-1">
                        <Label htmlFor="billing-from" className="text-[12px]">
                            De
                        </Label>
                        <Input
                            id="billing-from"
                            type="date"
                            value={filters.from}
                            onChange={(event) =>
                                apply({ from: event.target.value })
                            }
                            className="h-8 w-[150px]"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="billing-to" className="text-[12px]">
                            Até
                        </Label>
                        <Input
                            id="billing-to"
                            type="date"
                            value={filters.to}
                            onChange={(event) =>
                                apply({ to: event.target.value })
                            }
                            className="h-8 w-[150px]"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="billing-env" className="text-[12px]">
                            Ambiente
                        </Label>
                        <select
                            id="billing-env"
                            value={filters.environment}
                            onChange={(event) =>
                                apply({
                                    environment: event.target
                                        .value as AdminBillingProps['filters']['environment'],
                                })
                            }
                            className="border-input bg-background h-8 rounded-md border px-2 text-[13px]"
                        >
                            <option value="all">Todos</option>
                            <option value="sandbox">Sandbox</option>
                            <option value="production">Produção</option>
                        </select>
                    </div>
                    {filters.tab === 'payments' && (
                        <SearchInput
                            value={filters.q}
                            onChange={(value) => apply({ q: value })}
                            placeholder="Cliente, ULID ou id do Mercado Pago"
                        />
                    )}
                </div>
            </FilterBar>

            <KpiGrid min={150}>
                {revenue.length === 0 && (
                    <KpiCard
                        variant="compact"
                        label="Receita líquida no período"
                        value={formatCurrency(0)}
                        caption="Nenhum pagamento recebido no período."
                    />
                )}
                {revenue.map((line) => (
                    <KpiCard
                        key={line.currency}
                        variant="compact"
                        label={`Receita líquida (${line.currency})`}
                        value={formatCurrency(line.net_cents, line.currency)}
                        caption={`Bruto ${formatCurrency(line.gross_cents, line.currency)} · estornado ${formatCurrency(line.refunded_cents, line.currency)} · contestado ${formatCurrency(line.charged_back_cents, line.currency)} · ${formatNumber(line.approved_count)} pagamento(s) recebido(s) no período`}
                    />
                ))}
                <KpiCard
                    variant="compact"
                    label="Contestações em disputa"
                    value={formatNumber(counters.open_chargebacks)}
                    captionTone={
                        counters.open_chargebacks > 0 ? 'danger' : 'neutral'
                    }
                    caption="Envio suspenso enquanto o ciclo contestado for o vigente."
                />
                <KpiCard
                    variant="compact"
                    label="Inadimplentes"
                    value={formatNumber(counters.past_due)}
                    captionTone={counters.past_due > 0 ? 'warning' : 'neutral'}
                    caption="Envio bloqueado; leitura e download seguem."
                />
                <KpiCard
                    variant="compact"
                    label="Divergências abertas"
                    value={formatNumber(counters.open_divergences)}
                    captionTone={
                        counters.open_divergences > 0 ? 'warning' : 'neutral'
                    }
                    caption="Da conciliação com o Mercado Pago."
                />
                <KpiCard
                    variant="compact"
                    label="Estornos em aberto"
                    value={formatNumber(counters.open_refunds)}
                    caption={`${formatNumber(counters.pending_payments)} pagamento(s) pendente(s).`}
                />
            </KpiGrid>

            <div className="border-border bg-card shadow-card rounded-xl border">
                <UnderlineTabs<Tab>
                    value={filters.tab}
                    onChange={(tab) => apply({ tab })}
                    options={[
                        { value: 'payments', label: 'Pagamentos' },
                        { value: 'refunds', label: 'Estornos' },
                        {
                            value: 'chargebacks',
                            label: 'Contestações',
                            count: counters.open_chargebacks,
                        },
                        {
                            value: 'past_due',
                            label: 'Inadimplência',
                            count: counters.past_due,
                        },
                        {
                            value: 'divergences',
                            label: 'Conciliação',
                            count: counters.open_divergences,
                        },
                    ]}
                />

                {filters.tab === 'divergences' && (
                    <div className="flex gap-2 px-5 pt-3">
                        <Button
                            variant={
                                filters.divergences === 'open'
                                    ? 'secondary'
                                    : 'ghost'
                            }
                            size="xs"
                            onClick={() => apply({ divergences: 'open' })}
                        >
                            Abertas
                        </Button>
                        <Button
                            variant={
                                filters.divergences === 'all'
                                    ? 'secondary'
                                    : 'ghost'
                            }
                            size="xs"
                            onClick={() => apply({ divergences: 'all' })}
                        >
                            Todas
                        </Button>
                    </div>
                )}

                {table}

                {rows.meta.total > 0 && (
                    <TablePagination
                        paginated={rows}
                        entity="registros"
                        entitySingular="registro"
                        showPerPage={false}
                    />
                )}
            </div>

            <AdminBillingPanels
                reconciliation={props.reconciliation}
                methods={props.methods}
                refundPolicy={props.refund_policy}
                recurring={props.recurring}
                fiscal={props.fiscal}
            />

            <RefundDialog
                target={refundTarget}
                onOpenChange={(open) => !open && setRefundTarget(null)}
                submitUrl={
                    refundTarget ? refundPayment.url(refundTarget.id) : null
                }
                allowPartial
                password={password}
            />

            <ConfirmDialog
                open={cancelTarget !== null}
                onOpenChange={(open) => !open && setCancelTarget(null)}
                destructive
                processing={busy}
                title="Cancelar este pagamento pendente?"
                description={
                    cancelTarget
                        ? `${cancelTarget.organization?.name ?? 'Cliente'} — ${formatCurrency(cancelTarget.amount_cents, cancelTarget.currency)}. Só pagamentos pendentes podem ser cancelados; nada é cobrado.`
                        : undefined
                }
                confirmLabel="Cancelar pagamento"
                cancelLabel="Voltar"
                onConfirm={() => {
                    if (!cancelTarget) {
                        return;
                    }

                    const target = cancelTarget;

                    password.ensure(() =>
                        post(cancelPayment.url(target.id), {}, () =>
                            setCancelTarget(null),
                        ),
                    );
                }}
            />

            <ConfirmDialog
                open={resolveTarget !== null}
                onOpenChange={(open) => !open && setResolveTarget(null)}
                processing={busy}
                disabled={note.trim().length < 3}
                title="Marcar divergência como revisada?"
                description="Isto só registra a revisão. O pagamento não é alterado: o estado continua vindo do Mercado Pago."
                confirmLabel="Marcar revisada"
                cancelLabel="Voltar"
                onConfirm={() => {
                    if (!resolveTarget) {
                        return;
                    }

                    post(
                        resolveDivergence.url(resolveTarget.id),
                        { note },
                        () => setResolveTarget(null),
                    );
                }}
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="divergence-note">
                        O que foi verificado
                    </Label>
                    <Textarea
                        id="divergence-note"
                        rows={3}
                        maxLength={500}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                    />
                </div>
            </ConfirmDialog>

            {password.dialog}
        </>
    );
}

AdminBilling.layout = {
    breadcrumbs: [{ title: 'Planos e faturamento', href: adminBilling() }],
};
