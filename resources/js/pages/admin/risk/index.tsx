import { Head, Link, router } from '@inertiajs/react';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { FilterBar, FilterChip } from '@/components/filter-bar';
import { PageHeader } from '@/components/page-header';
import { RiskNav } from '@/components/risk/risk-nav';
import {
    RiskReviewStatusBadge,
    RiskStatusBadge,
} from '@/components/risk/risk-status-badge';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { formatDateTime, plural } from '@/lib/format';
import {
    index as adminRiskIndex,
    show as adminRiskShow,
} from '@/routes/admin/risk';
import type { Paginated } from '@/types';

interface Option {
    value: string;
    label: string;
}

export interface RiskReviewSummary {
    id: string;
    status: string;
    status_label: string;
    trigger: string;
    opened_at: string;
    appeal_requested_at: string | null;
    organization: { id: string; name: string; risk_status: string };
    rules: { rule: string; label: string; signals: number }[];
    score: number;
}

export interface AdminRiskIndexProps {
    filters: { status: string; appeal: boolean };
    statuses: Option[];
    counts: { open: number; appeals: number };
    reviews: Paginated<RiskReviewSummary>;
}

/**
 * Painel interno › Antifraude › Fila de revisão (Fase 3 §3.7). Cada caso
 * mostra as regras que o abriram; a decisão é tomada no detalhe, sempre com
 * motivo.
 */
export default function AdminRiskIndex({
    filters,
    statuses,
    counts,
    reviews,
}: AdminRiskIndexProps) {
    const apply = (next: Partial<AdminRiskIndexProps['filters']>) => {
        const merged = { ...filters, ...next };

        router.get(
            adminRiskIndex.url({
                query: {
                    status: merged.status,
                    appeal: merged.appeal ? 1 : undefined,
                },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const columns: DataTableColumn<RiskReviewSummary>[] = [
        {
            key: 'org',
            header: 'Organização',
            width: 'minmax(0,1.4fr)',
            cell: (row) => (
                <div className="flex min-w-0 flex-col gap-1">
                    <Link
                        href={adminRiskShow(row.id)}
                        className="text-primary truncate text-[13.5px] font-medium"
                    >
                        {row.organization.name}
                    </Link>
                    <RiskStatusBadge status={row.organization.risk_status} />
                </div>
            ),
        },
        {
            key: 'rules',
            header: 'Regras',
            width: 'minmax(0,2fr)',
            cell: (row) =>
                row.rules.length === 0 ? (
                    <span className="text-muted-foreground text-[13px]">
                        Sem sinais (aberto por pedido de revisão)
                    </span>
                ) : (
                    <ul className="flex flex-col gap-0.5 text-[12.5px]">
                        {row.rules.map((rule) => (
                            <li key={rule.rule} className="truncate">
                                {rule.label}
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {plural(rule.signals, 'sinal', 'sinais')}
                                </span>
                            </li>
                        ))}
                    </ul>
                ),
        },
        {
            key: 'score',
            header: 'Pontos',
            width: '.6fr',
            cell: (row) => (
                <span className="tabular text-[13px] font-medium">
                    {row.score}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Situação',
            width: 'minmax(0,1.1fr)',
            cell: (row) => (
                <div className="flex flex-col items-start gap-1">
                    <RiskReviewStatusBadge status={row.status} />
                    {row.appeal_requested_at && (
                        <Badge variant="info">Revisão pedida</Badge>
                    )}
                </div>
            ),
        },
        {
            key: 'opened',
            header: 'Aberto em',
            width: '1fr',
            cell: (row) => (
                <span className="text-text-secondary tabular text-[13px] whitespace-nowrap">
                    {formatDateTime(row.opened_at)}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title="Antifraude" />
            <PageHeader
                title="Antifraude"
                subtitle={`${plural(counts.open, 'caso')} aguardando revisão, ${counts.appeals} com pedido da organização. A ação automática máxima é suspender o envio de novos documentos; nada já enviado é alterado.`}
            />
            <RiskNav current="queue" />
            <div className="border-border bg-card shadow-card rounded-xl border">
                <FilterBar>
                    <FilterChip
                        label="Situação"
                        value={filters.status}
                        options={statuses}
                        onChange={(status) =>
                            apply({ status: status ?? 'open' })
                        }
                    />
                    <FilterChip
                        label="Pedido de revisão"
                        value={filters.appeal ? '1' : null}
                        options={[{ value: '1', label: 'Só com pedido' }]}
                        onChange={(value) => apply({ appeal: value === '1' })}
                        allLabel="Todos"
                    />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={reviews.data}
                    rowKey={(row) => row.id}
                    minWidth={900}
                    empty={
                        <EmptyState
                            variant="inline"
                            title="Nenhum caso nesta situação."
                        />
                    }
                />
                <TablePagination
                    paginated={reviews}
                    entity="casos"
                    entitySingular="caso"
                    showPerPage={false}
                />
            </div>
        </>
    );
}

AdminRiskIndex.layout = {
    breadcrumbs: [{ title: 'Antifraude', href: adminRiskIndex() }],
};
