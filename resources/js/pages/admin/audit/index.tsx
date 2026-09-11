import { Head, Link, router } from '@inertiajs/react';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import {
    ClearFiltersButton,
    FilterBar,
    FilterChip,
} from '@/components/filter-bar';
import { PageHeader } from '@/components/page-header';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';
import { index as adminAudit } from '@/routes/admin/audit';
import { show as adminOrganizationShow } from '@/routes/admin/organizations';
import type { Paginated } from '@/types';

interface Option {
    value: string;
    label: string;
}

interface PlatformEvent {
    id: string;
    occurred_at: string;
    action: string;
    label: string;
    kind: 'ok' | 'info' | 'warn';
    actor: string;
    organization: { id: string; name: string } | null;
    details: { label: string; value: string }[];
    ip: string | null;
}

export interface AdminAuditProps {
    filters: {
        action: string | null;
        actor: string | null;
        from: string | null;
        to: string | null;
    };
    actions: Option[];
    actors: Option[];
    events: Paginated<PlatformEvent>;
}

const KIND_TONE = { ok: 'success', info: 'neutral', warn: 'warning' } as const;

/**
 * Painel interno › Logs e auditoria (Fase 2 — flag `admin_audit`): ações da
 * equipe da plataforma (bloqueios, "acessar como"). Somente leitura — a
 * tabela é append-only.
 */
export default function AdminAuditIndex({
    filters,
    actions,
    actors,
    events,
}: AdminAuditProps) {
    const apply = (next: Partial<AdminAuditProps['filters']>) => {
        router.get(
            adminAudit.url({ query: { ...filters, ...next, page: undefined } }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const hasFilters =
        !!filters.action || !!filters.actor || !!filters.from || !!filters.to;

    const columns: DataTableColumn<PlatformEvent>[] = [
        {
            key: 'when',
            header: 'Quando',
            width: '1fr',
            cell: (row) => (
                <span className="text-text-secondary tabular text-[13px] whitespace-nowrap">
                    {formatDateTime(row.occurred_at)}
                </span>
            ),
        },
        {
            key: 'action',
            header: 'Ação',
            width: 'minmax(0,1.3fr)',
            cell: (row) => (
                <Badge variant={KIND_TONE[row.kind]} dot>
                    {row.label}
                </Badge>
            ),
        },
        {
            key: 'actor',
            header: 'Quem',
            width: 'minmax(0,1fr)',
            cell: (row) => (
                <span className="text-text-secondary truncate text-[13px]">
                    {row.actor}
                </span>
            ),
        },
        {
            key: 'org',
            header: 'Organização',
            width: 'minmax(0,1.2fr)',
            cell: (row) =>
                row.organization ? (
                    <Link
                        href={adminOrganizationShow(row.organization.id)}
                        className="text-primary truncate text-[13px] font-medium"
                    >
                        {row.organization.name}
                    </Link>
                ) : (
                    <span className="text-muted-foreground text-[13px]">—</span>
                ),
        },
        {
            key: 'details',
            header: 'Detalhes',
            width: 'minmax(0,2fr)',
            cell: (row) =>
                row.details.length === 0 ? (
                    <span className="text-muted-foreground text-[13px]">—</span>
                ) : (
                    <dl className="grid grid-cols-[auto_1fr] gap-x-2 text-[12.5px]">
                        {row.details.map((detail) => (
                            <div key={detail.label} className="contents">
                                <dt className="text-muted-foreground">
                                    {detail.label}
                                </dt>
                                <dd className="truncate" title={detail.value}>
                                    {detail.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                ),
        },
        {
            key: 'ip',
            header: 'IP',
            width: '.8fr',
            cell: (row) => (
                <span className="text-muted-foreground font-mono text-[12px]">
                    {row.ip ?? '—'}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title="Logs e auditoria" />
            <PageHeader
                title="Logs e auditoria"
                subtitle="Ações da equipe AssinaVelox: bloqueios de conta e acessos de suporte. Registro somente leitura."
            />
            <div className="border-border bg-card shadow-card rounded-xl border">
                <FilterBar>
                    <FilterChip
                        label="Ação"
                        value={filters.action}
                        options={actions}
                        onChange={(action) => apply({ action })}
                        allLabel="Todas"
                    />
                    <FilterChip
                        label="Quem"
                        value={filters.actor}
                        options={actors}
                        onChange={(actor) => apply({ actor })}
                    />
                    <Input
                        type="date"
                        aria-label="De"
                        value={filters.from ?? ''}
                        max={filters.to ?? undefined}
                        onChange={(e) =>
                            apply({ from: e.target.value || null })
                        }
                        className="h-[34px] w-[150px] text-[13px]"
                    />
                    <Input
                        type="date"
                        aria-label="Até"
                        value={filters.to ?? ''}
                        min={filters.from ?? undefined}
                        onChange={(e) => apply({ to: e.target.value || null })}
                        className="h-[34px] w-[150px] text-[13px]"
                    />
                    <ClearFiltersButton
                        visible={hasFilters}
                        onClick={() =>
                            apply({
                                action: null,
                                actor: null,
                                from: null,
                                to: null,
                            })
                        }
                    />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={events.data}
                    rowKey={(row) => row.id}
                    minWidth={960}
                    empty={
                        <EmptyState
                            variant="inline"
                            title={
                                hasFilters
                                    ? 'Nenhum registro com estes filtros.'
                                    : 'Nenhuma ação da equipe registrada ainda.'
                            }
                        />
                    }
                />
                <TablePagination
                    paginated={events}
                    entity="registros"
                    entitySingular="registro"
                    showPerPage={false}
                />
            </div>
        </>
    );
}

AdminAuditIndex.layout = {
    breadcrumbs: [{ title: 'Logs e auditoria', href: adminAudit() }],
};
