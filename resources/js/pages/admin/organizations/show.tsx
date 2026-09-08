import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { AvatarInitials } from '@/components/avatar-initials';
import { CopyButton } from '@/components/copy-button';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { KpiCard, KpiGrid } from '@/components/kpi-card';
import { PageHeader } from '@/components/page-header';
import { ProgressMeter } from '@/components/progress-meter';
import { PaymentStatusBadge } from '@/components/status/payment-status-badge';
import { SubscriptionStatusBadge } from '@/components/status/subscription-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatBytes,
    formatCurrency,
    formatCurrencyCompact,
    formatDateMedium,
    formatNumber,
    formatRelativeDateTime,
} from '@/lib/format';
import { membershipStatusTones, planLabels } from '@/lib/labels';
import {
    index as adminOrganizations,
    show as adminOrganizationShow,
} from '@/routes/admin/organizations';
import type {
    Membership,
    Payment,
    PlanCode,
    PlanUsage,
    Subscription,
} from '@/types';

export interface AdminOrganizationShowProps {
    customer: {
        id: string;
        public_id: string;
        name: string;
        legal_name: string | null;
        initials: string;
        tax_id_masked: string | null;
        contact_email: string | null;
        timezone: string;
        created_at: string;
        last_seen_at: string | null;
        deletion_requested_at: string | null;
    };
    kpis: {
        envelopes_total: number;
        envelopes_completed: number;
        envelopes_in_progress: number;
        members_active: number;
        storage_used_bytes: number;
        mrr_cents: number;
    };
    subscription: Subscription & { plan: { key: PlanCode; name: string } };
    usage: PlanUsage;
    members: Membership[];
    payments: Payment[];
}

const PLAN_BADGE: Record<
    PlanCode,
    'planFree' | 'planProfessional' | 'planEnterprise'
> = {
    free: 'planFree',
    professional: 'planProfessional',
    enterprise: 'planEnterprise',
};

/** Painel interno › Cliente (ROUTES §1.5 — sem mock): cabeçalho + KPIs + membros + assinatura/pagamentos (somente leitura). */
export default function AdminOrganizationShow({
    customer,
    kpis,
    subscription,
    usage,
    members,
    payments,
}: AdminOrganizationShowProps) {
    const memberColumns: DataTableColumn<Membership>[] = [
        {
            key: 'user',
            header: 'Usuário',
            width: 'minmax(0,2fr)',
            cell: (m, index) => (
                <div className="flex min-w-0 items-center gap-3">
                    <AvatarInitials
                        initials={m.user.initials}
                        index={index}
                        size="lg"
                    />
                    <span className="min-w-0">
                        <span className="block truncate font-semibold">
                            {m.user.name}
                        </span>
                        <span className="text-muted-foreground block truncate text-[12.5px]">
                            {m.user.email}
                        </span>
                    </span>
                </div>
            ),
        },
        {
            key: 'role',
            header: 'Função',
            width: '1fr',
            cell: (m) => (
                <span className="text-text-secondary text-[13px]">
                    {m.role_label}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '1fr',
            cell: (m) => (
                <Badge variant={membershipStatusTones[m.status]} dot>
                    {m.status_label}
                </Badge>
            ),
        },
        {
            key: 'tfa',
            header: '2FA',
            width: '.8fr',
            cell: (m) => (
                <span
                    className={
                        m.two_factor_enabled
                            ? 'text-success text-[13px] font-semibold'
                            : 'text-danger text-[13px] font-semibold'
                    }
                >
                    {m.two_factor_enabled ? 'Ativa' : 'Inativa'}
                </span>
            ),
        },
        {
            key: 'last',
            header: 'Último acesso',
            width: '1.1fr',
            cell: (m) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {formatRelativeDateTime(m.last_seen_at)}
                </span>
            ),
        },
    ];

    const paymentColumns: DataTableColumn<Payment>[] = [
        {
            key: 'date',
            header: 'Data',
            width: '1fr',
            cell: (p) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {formatDateMedium(p.paid_at ?? p.created_at)}
                </span>
            ),
        },
        {
            key: 'desc',
            header: 'Descrição',
            width: 'minmax(0,2fr)',
            cell: (p) => <span className="truncate">{p.description}</span>,
        },
        {
            key: 'amount',
            header: 'Valor',
            width: '1fr',
            cell: (p) => (
                <span className="tabular font-semibold">
                    {formatCurrency(p.amount_cents)}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '1fr',
            cell: (p) => (
                <PaymentStatusBadge status={p.status} label={p.status_label} />
            ),
        },
        {
            key: 'mp',
            header: 'Mercado Pago',
            width: '1fr',
            cell: (p) => (
                <span className="text-muted-foreground font-mono text-[12px]">
                    {p.mp_payment_id ?? '—'}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title={customer.name} />
            <PageHeader
                size="detail"
                leading={
                    <Button
                        asChild
                        variant="outline"
                        size="icon-sm"
                        aria-label="Voltar"
                    >
                        <Link href={adminOrganizations()}>
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                }
                title={customer.name}
                badge={
                    <>
                        <Badge variant={PLAN_BADGE[subscription.plan.key]}>
                            {subscription.plan.name ||
                                planLabels[subscription.plan.key]}
                        </Badge>
                        <SubscriptionStatusBadge
                            status={subscription.status}
                            label={subscription.status_label}
                            cancelAtPeriodEnd={
                                subscription.cancel_at_period_end
                            }
                        />
                    </>
                }
                subtitle={
                    <span className="inline-flex flex-wrap items-center gap-1.5">
                        <span className="tabular font-mono">
                            {customer.public_id}
                        </span>
                        <CopyButton
                            value={customer.public_id}
                            label="Copiar ID"
                            size="icon-xs"
                            toastMessage="ID copiado"
                            className="size-6"
                        />
                        {customer.legal_name && <>· {customer.legal_name}</>}
                        {customer.tax_id_masked && (
                            <>· {customer.tax_id_masked}</>
                        )}
                        · criada em {formatDateMedium(customer.created_at)}
                    </span>
                }
            />

            {customer.deletion_requested_at && (
                <div className="border-danger-border bg-danger-bg text-danger rounded-[10px] border p-3.5 text-[13px] font-medium">
                    Exclusão solicitada em{' '}
                    {formatDateMedium(customer.deletion_requested_at)}.
                </div>
            )}

            <KpiGrid min={150}>
                <KpiCard
                    variant="compact"
                    label="Documentos (total)"
                    value={formatNumber(kpis.envelopes_total)}
                    caption={`${formatNumber(kpis.envelopes_completed)} concluídos`}
                    captionTone="success"
                />
                <KpiCard
                    variant="compact"
                    label="Em andamento"
                    value={formatNumber(kpis.envelopes_in_progress)}
                    caption="aguardando signatários"
                    captionTone="warning"
                />
                <KpiCard
                    variant="compact"
                    label="Usuários ativos"
                    value={formatNumber(kpis.members_active)}
                    caption={
                        usage.members.limit !== null
                            ? `de ${usage.members.limit} assentos`
                            : 'sem limite'
                    }
                />
                <KpiCard
                    variant="compact"
                    label="Armazenamento"
                    value={formatBytes(kpis.storage_used_bytes)}
                    caption={
                        usage.storage.limit_bytes
                            ? `de ${formatBytes(usage.storage.limit_bytes)}`
                            : 'sem limite'
                    }
                />
                <KpiCard
                    variant="compact"
                    label="MRR"
                    value={formatCurrencyCompact(kpis.mrr_cents)}
                    caption={
                        subscription.interval === 'yearly'
                            ? 'cobrança anual'
                            : 'cobrança mensal'
                    }
                />
            </KpiGrid>

            <div className="flex flex-wrap items-start gap-4">
                <div className="border-border bg-card shadow-card min-w-0 flex-[1.4_1_420px] rounded-xl border">
                    <div className="px-5 pt-[18px] pb-3">
                        <Heading
                            variant="small"
                            title="Membros"
                            description={`${formatNumber(members.length)} usuários na organização`}
                        />
                    </div>
                    <DataTable
                        columns={memberColumns}
                        rows={members}
                        rowKey={(m) => m.id}
                        minWidth={640}
                        empty={
                            <EmptyState
                                variant="inline"
                                title="Nenhum membro"
                            />
                        }
                    />
                </div>

                <div className="flex min-w-0 flex-[1_1_340px] flex-col gap-4">
                    <div className="border-border bg-card shadow-card flex flex-col gap-3.5 rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Assinatura"
                            description={
                                subscription.current_period_start &&
                                subscription.current_period_end
                                    ? `Ciclo ${formatDateMedium(subscription.current_period_start)} → ${formatDateMedium(subscription.current_period_end)}`
                                    : 'Sem ciclo de cobrança (plano Grátis)'
                            }
                        />
                        <ProgressMeter
                            label="Documentos"
                            used={usage.envelopes.used}
                            limit={usage.envelopes.limit}
                            size="sm"
                        />
                        <ProgressMeter
                            label="Usuários"
                            used={usage.members.used}
                            limit={usage.members.limit}
                            size="sm"
                        />
                        <ProgressMeter
                            label="Armazenamento"
                            used={usage.storage.used_bytes}
                            limit={usage.storage.limit_bytes}
                            usedLabel={formatBytes(usage.storage.used_bytes)}
                            limitLabel={
                                usage.storage.limit_bytes
                                    ? formatBytes(usage.storage.limit_bytes)
                                    : undefined
                            }
                            size="sm"
                        />
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-[12.5px]">
                            <dt className="text-muted-foreground">Contato</dt>
                            <dd className="truncate">
                                {customer.contact_email ?? '—'}
                            </dd>
                            <dt className="text-muted-foreground">Fuso</dt>
                            <dd>{customer.timezone}</dd>
                            <dt className="text-muted-foreground">
                                Último acesso
                            </dt>
                            <dd>
                                {formatRelativeDateTime(customer.last_seen_at)}
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>

            <div className="border-border bg-card shadow-card rounded-xl border">
                <div className="px-5 pt-[18px] pb-3">
                    <Heading
                        variant="small"
                        title="Pagamentos"
                        description="Últimos pagamentos registrados via Mercado Pago."
                    />
                </div>
                <DataTable
                    columns={paymentColumns}
                    rows={payments}
                    rowKey={(p) => p.id}
                    minWidth={720}
                    empty={
                        <EmptyState
                            variant="inline"
                            title="Nenhum pagamento registrado"
                        />
                    }
                />
            </div>
        </>
    );
}

AdminOrganizationShow.layout = (props: AdminOrganizationShowProps) => ({
    breadcrumbs: [
        { title: 'Clientes', href: adminOrganizations() },
        {
            title: props.customer.name,
            href: adminOrganizationShow(props.customer.id),
        },
    ],
});
