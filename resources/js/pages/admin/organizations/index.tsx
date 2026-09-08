import { Head, Link, router } from '@inertiajs/react';
import { Copy, Download, MoreHorizontal } from 'lucide-react';
import { toast } from 'sonner';
import { AvatarInitials } from '@/components/avatar-initials';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { FilterBar, FilterChip, SearchInput } from '@/components/filter-bar';
import { KpiCard, KpiGrid } from '@/components/kpi-card';
import { PageHeader } from '@/components/page-header';
import { ProgressMeter } from '@/components/progress-meter';
import { UnderlineTabs } from '@/components/segmented-control';
import { SubscriptionStatusBadge } from '@/components/status/subscription-status-badge';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    formatCurrency,
    formatCurrencyCompact,
    formatCurrencyShort,
    formatNumber,
    formatPercent,
    formatRelativeDateTime,
} from '@/lib/format';
import { adminOrgTabLabels, planLabels } from '@/lib/labels';
import {
    exportMethod as adminExport,
    index as adminOrganizations,
    show as adminOrganizationShow,
} from '@/routes/admin/organizations';
import type { Paginated, PlanCode, SubscriptionStatus, UserRef } from '@/types';

type AdminOrgTab = 'all' | 'active' | 'trialing' | 'past_due' | 'canceled';

export interface AdminOrganizationRow {
    id: string;
    public_id: string;
    name: string;
    initials: string;
    plan: { key: PlanCode; name: string };
    members: { used: number; limit: number | null };
    envelopes_cycle: { used: number; limit: number | null; pct: number | null };
    mrr_cents: number;
    subscription_status: SubscriptionStatus;
    status_label: string;
    last_seen_at: string | null;
    created_at: string;
    owner: UserRef & { email: string };
}

export interface AdminOrganizationsIndexProps {
    filters: {
        status: AdminOrgTab;
        plan: PlanCode | null;
        created_from: string | null;
        created_to: string | null;
        q: string;
    };
    kpis: {
        active_accounts: { value: number; new_this_month: number };
        mrr_cents: { value: number; delta_pct: number | null };
        envelopes_today: {
            value: number;
            peak_hour: number | null;
            peak_count: number | null;
        };
        trials_expiring_7d: { value: number; without_envelope: number };
        past_due: { value: number; overdue_cents: number };
    };
    tabs: Record<AdminOrgTab, number>;
    customers: Paginated<AdminOrganizationRow>;
}

const PLAN_BADGE: Record<
    PlanCode,
    'planFree' | 'planProfessional' | 'planEnterprise'
> = {
    free: 'planFree',
    professional: 'planProfessional',
    enterprise: 'planEnterprise',
};

/** Painel interno › Clientes (ROUTES §2.20; DESIGN §6.13). */
export default function AdminOrganizationsIndex({
    filters,
    kpis,
    tabs,
    customers,
}: AdminOrganizationsIndexProps) {
    const apply = (next: Partial<AdminOrganizationsIndexProps['filters']>) => {
        router.get(
            adminOrganizations.url({
                query: { ...filters, ...next, page: undefined },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const columns: DataTableColumn<AdminOrganizationRow>[] = [
        {
            key: 'name',
            header: 'Empresa',
            width: 'minmax(0,2.2fr)',
            cell: (org, index) => (
                <div className="flex min-w-0 items-center gap-3">
                    <AvatarInitials
                        initials={org.initials}
                        index={index}
                        size="lg"
                    />
                    <span className="min-w-0">
                        <Link
                            href={adminOrganizationShow(org.id)}
                            className="hover:text-primary block truncate font-semibold"
                        >
                            {org.name}
                        </Link>
                        <span className="text-muted-foreground tabular block truncate text-[12px]">
                            {org.public_id} · {org.owner.email}
                        </span>
                    </span>
                </div>
            ),
        },
        {
            key: 'plan',
            header: 'Plano',
            width: '1fr',
            cell: (org) => (
                <Badge variant={PLAN_BADGE[org.plan.key]}>
                    {org.plan.name || planLabels[org.plan.key]}
                </Badge>
            ),
        },
        {
            key: 'members',
            header: 'Usuários',
            width: '.9fr',
            cell: (org) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {org.members.used}
                    {org.members.limit !== null && ` / ${org.members.limit}`}
                </span>
            ),
        },
        {
            key: 'envelopes',
            header: 'Documentos no ciclo',
            width: '1.3fr',
            cell: (org) => (
                <div className="w-full pr-3">
                    <ProgressMeter
                        label=""
                        used={org.envelopes_cycle.used}
                        limit={org.envelopes_cycle.limit}
                        size="sm"
                        className="[&>div:first-child]:justify-end"
                    />
                </div>
            ),
        },
        {
            key: 'mrr',
            header: 'MRR',
            width: '1fr',
            cell: (org) => (
                <span className="tabular font-semibold">
                    {formatCurrencyCompact(org.mrr_cents)}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '1.1fr',
            cell: (org) => (
                <SubscriptionStatusBadge
                    status={org.subscription_status}
                    label={org.status_label}
                />
            ),
        },
        {
            key: 'last_seen',
            header: 'Último acesso',
            width: '1fr',
            cell: (org) => (
                <span className="text-text-secondary tabular text-[13px] whitespace-nowrap">
                    {formatRelativeDateTime(org.last_seen_at)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            width: '120px',
            align: 'right',
            cell: (org) => (
                <div className="flex items-center justify-end gap-1">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span>
                                <Button
                                    variant="outline-sm"
                                    size="xxs"
                                    disabled
                                >
                                    Acessar como
                                </Button>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>
                            Impersonação chega na Fase 2
                        </TooltipContent>
                    </Tooltip>
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
                                <Link href={adminOrganizationShow(org.id)}>
                                    Ver detalhes
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => {
                                    void navigator.clipboard?.writeText(
                                        org.public_id,
                                    );
                                    toast.success('ID copiado');
                                }}
                            >
                                <Copy className="size-3.5" />
                                Copiar ID
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            ),
        },
    ];

    const tabOptions = (Object.keys(adminOrgTabLabels) as AdminOrgTab[]).map(
        (key) => ({
            value: key,
            label: adminOrgTabLabels[key],
            count: tabs[key],
        }),
    );

    return (
        <>
            <Head title="Clientes" />
            <PageHeader
                title="Clientes"
                subtitle="Todas as contas da plataforma. Dados somente leitura; toda ação é registrada na trilha."
                actions={
                    <Button asChild variant="outline">
                        <a href={adminExport.url({ query: filters })}>
                            <Download className="size-[15px]" />
                            Exportar
                        </a>
                    </Button>
                }
            />

            <KpiGrid min={150}>
                <KpiCard
                    variant="compact"
                    label="Contas ativas"
                    value={formatNumber(kpis.active_accounts.value)}
                    caption={`+${formatNumber(kpis.active_accounts.new_this_month)} este mês`}
                    captionTone="success"
                />
                <KpiCard
                    variant="compact"
                    label="MRR"
                    value={formatCurrencyShort(kpis.mrr_cents.value)}
                    caption={
                        kpis.mrr_cents.delta_pct !== null
                            ? `${kpis.mrr_cents.delta_pct >= 0 ? '+' : ''}${formatPercent(kpis.mrr_cents.delta_pct)} vs. mês anterior`
                            : '—'
                    }
                    captionTone={
                        kpis.mrr_cents.delta_pct !== null &&
                        kpis.mrr_cents.delta_pct < 0
                            ? 'danger'
                            : 'success'
                    }
                />
                <KpiCard
                    variant="compact"
                    label="Documentos hoje"
                    value={formatNumber(kpis.envelopes_today.value)}
                    caption={
                        kpis.envelopes_today.peak_hour !== null
                            ? `pico às ${kpis.envelopes_today.peak_hour}h · ${formatNumber(kpis.envelopes_today.peak_count ?? 0)}/h`
                            : 'sem envios ainda'
                    }
                />
                <KpiCard
                    variant="compact"
                    label="Trials expirando (7 d)"
                    value={formatNumber(kpis.trials_expiring_7d.value)}
                    caption={`${formatNumber(kpis.trials_expiring_7d.without_envelope)} sem documento enviado`}
                    captionTone="warning"
                />
                <KpiCard
                    variant="compact"
                    label="Inadimplentes"
                    value={formatNumber(kpis.past_due.value)}
                    caption={`${formatCurrency(kpis.past_due.overdue_cents)} em atraso`}
                    captionTone="danger"
                />
            </KpiGrid>

            <div className="border-border bg-card shadow-card rounded-xl border">
                <UnderlineTabs
                    value={filters.status}
                    onChange={(status) => apply({ status })}
                    options={tabOptions}
                />
                <FilterBar>
                    <SearchInput
                        value={filters.q}
                        onChange={(q) => apply({ q })}
                        placeholder="Buscar por empresa, CNPJ, e-mail ou ID da conta"
                    />
                    <FilterChip
                        label="Plano"
                        value={filters.plan}
                        onChange={(plan) =>
                            apply({ plan: plan as PlanCode | null })
                        }
                        options={(Object.keys(planLabels) as PlanCode[]).map(
                            (key) => ({ value: key, label: planLabels[key] }),
                        )}
                    />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={customers.data}
                    rowKey={(org) => org.id}
                    minWidth={1080}
                    empty={
                        <EmptyState
                            variant="inline"
                            title="Nenhuma conta encontrada"
                        />
                    }
                />
                <TablePagination
                    paginated={customers}
                    entity="contas"
                    entitySingular="conta"
                    showPerPage={false}
                />
            </div>
        </>
    );
}

AdminOrganizationsIndex.layout = {
    breadcrumbs: [{ title: 'Clientes', href: adminOrganizations() }],
};
