import { Head, router } from '@inertiajs/react';
import { History } from 'lucide-react';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import {
    ClearFiltersButton,
    FilterBar,
    FilterChip,
} from '@/components/filter-bar';
import Heading from '@/components/heading';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';
import { index as envelopesIndex } from '@/routes/envelopes';
import { audit as settingsAudit } from '@/routes/settings';
import type { Paginated } from '@/types';

interface AuditRow {
    id: string;
    occurred_at: string;
    type: string;
    label: string;
    kind: 'ok' | 'info' | 'warn';
    category: string | null;
    category_label: string | null;
    actor: { kind: 'user' | 'system' | 'support'; name: string };
    details: { label: string; value: string }[];
    ip: string | null;
}

interface Option {
    value: string;
    label: string;
}

type SettingsAuditProps =
    | { enabled: false }
    | {
          enabled: true;
          filters: {
              category: string | null;
              actor: string | null;
              from: string | null;
              to: string | null;
          };
          categories: Option[];
          actors: Option[];
          events: Paginated<AuditRow>;
      };

const KIND_TONE = { ok: 'success', info: 'neutral', warn: 'warning' } as const;

/**
 * Configurações › Registro de atividades (Fase 2 — flag `audit_log`,
 * permissão "Ver registro de atividades da conta"). Somente leitura; eventos
 * administrativos da organização, sem dados de signatários.
 */
export default function SettingsAudit(props: SettingsAuditProps) {
    if (!props.enabled) {
        return (
            <>
                <Head title="Registro de atividades" />
                <Phase2EmptyState
                    title="O registro de atividades estará disponível na Fase 2"
                    description="Veja quem alterou usuários, funções, times, etiquetas e o plano da conta, e quando o suporte acessou a conta."
                    ctaHref={envelopesIndex.url()}
                />
            </>
        );
    }

    const { filters, categories, actors, events } = props;

    const apply = (next: Partial<typeof filters>) => {
        router.get(
            settingsAudit.url({
                query: { ...filters, ...next, page: undefined },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const hasFilters =
        !!filters.category || !!filters.actor || !!filters.from || !!filters.to;

    const columns: DataTableColumn<AuditRow>[] = [
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
            key: 'event',
            header: 'Evento',
            width: 'minmax(0,1.6fr)',
            cell: (row) => (
                <span className="flex min-w-0 flex-col gap-0.5">
                    <span className="flex items-center gap-2">
                        <Badge variant={KIND_TONE[row.kind]} dot>
                            {row.label}
                        </Badge>
                    </span>
                    {row.category_label && (
                        <span className="text-muted-foreground text-[12px]">
                            {row.category_label}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'actor',
            header: 'Quem',
            width: 'minmax(0,1.2fr)',
            cell: (row) => (
                <span
                    className={
                        row.actor.kind === 'support'
                            ? 'text-warning truncate text-[13px] font-semibold'
                            : 'text-text-secondary truncate text-[13px]'
                    }
                >
                    {row.actor.name}
                </span>
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
            <Head title="Registro de atividades" />
            <div className="border-border bg-card shadow-card rounded-xl border">
                <div className="px-5 pt-[18px]">
                    <Heading
                        variant="small"
                        title="Registro de atividades"
                        description="Quem alterou usuários, funções, times, etiquetas, plano e exportações — e quando o suporte da AssinaVelox acessou a conta. Somente leitura."
                    />
                </div>
                <FilterBar>
                    <FilterChip
                        label="Categoria"
                        value={filters.category}
                        options={categories}
                        onChange={(category) => apply({ category })}
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
                                category: null,
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
                    minWidth={820}
                    empty={
                        <EmptyState
                            variant="inline"
                            icon={History}
                            title={
                                hasFilters
                                    ? 'Nenhuma atividade com estes filtros.'
                                    : 'Nenhuma atividade administrativa registrada ainda.'
                            }
                        />
                    }
                />
                <TablePagination
                    paginated={events}
                    entity="atividades"
                    entitySingular="atividade"
                    gender="f"
                />
            </div>
        </>
    );
}

SettingsAudit.layout = {
    breadcrumbs: [
        { title: 'Configurações', href: settingsAudit() },
        { title: 'Registro de atividades', href: settingsAudit() },
    ],
};
