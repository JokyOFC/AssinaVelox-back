import { Head, router } from '@inertiajs/react';
import { Download, Info } from 'lucide-react';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import {
    ClearFiltersButton,
    FilterBar,
    FilterChip,
} from '@/components/filter-bar';
import Heading from '@/components/heading';
import { KpiCard, KpiGrid } from '@/components/kpi-card';
import { PageHeader } from '@/components/page-header';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { ProgressMeter } from '@/components/progress-meter';
import {
    asDay,
    SeriesBars,
    type SeriesPoint,
} from '@/components/reports/series-bars';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    formatDateMedium,
    formatDuration,
    formatNumber,
    formatPercent,
} from '@/lib/format';
import { index as envelopesIndex } from '@/routes/envelopes';
import {
    exportMethod as reportsExport,
    index as reportsIndex,
} from '@/routes/reports';

interface Option {
    value: string;
    label: string;
}

interface Filters {
    from: string;
    to: string;
    status: string | null;
    folder: string | null;
    team: string | null;
    creator: string | null;
    tag: string | null;
}

interface BreakdownBase {
    sent: number;
    completed: number;
    closed_unsigned: number;
    avg_minutes_to_complete: number | null;
}

type UserRow = BreakdownBase & { user: { id: string; name: string } };
type TeamRow = BreakdownBase & { team: { id: string | null; name: string } };

type ReportsIndexProps =
    | { enabled: false }
    | {
          enabled: true;
          filters: Filters;
          options: {
              folders: Option[];
              teams: Option[];
              creators: Option[];
              statuses: Option[];
              tags: Option[];
          };
          report: {
              totals: {
                  sent: number;
                  completed: number;
                  refused: number;
                  expired: number;
                  canceled: number;
                  pending: number;
                  avg_minutes_to_complete: number | null;
                  completion_rate: number | null;
              };
              series: SeriesPoint[];
              by_user: UserRow[];
              by_team: TeamRow[];
          };
          plan_usage: {
              plan_name: string;
              envelopes_committed_in_period: number;
              cycle_used: number;
              cycle_limit: number | null;
              cycle_end: string | null;
          } | null;
          scope: { all_envelopes: boolean };
          can: { export: boolean };
      };

const PRESETS: { days: number; label: string }[] = [
    { days: 7, label: '7 dias' },
    { days: 30, label: '30 dias' },
    { days: 90, label: '90 dias' },
    { days: 365, label: '12 meses' },
];

function isoDaysAgo(to: string, days: number): string {
    const date = new Date(`${to}T12:00:00`);
    date.setDate(date.getDate() - (days - 1));

    return date.toISOString().slice(0, 10);
}

function breakdownColumns<T extends BreakdownBase>(
    first: DataTableColumn<T>,
): DataTableColumn<T>[] {
    const numeric = (key: keyof BreakdownBase, header: string) => ({
        key,
        header,
        width: '.9fr',
        align: 'right' as const,
        cell: (row: T) => (
            <span className="tabular text-[13px]">
                {formatNumber(row[key] as number)}
            </span>
        ),
    });

    return [
        first,
        numeric('sent', 'Enviados'),
        numeric('completed', 'Concluídos'),
        numeric('closed_unsigned', 'Sem assinatura'),
        {
            key: 'avg',
            header: 'Tempo médio',
            width: '1fr',
            align: 'right',
            cell: (row) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {formatDuration(row.avg_minutes_to_complete)}
                </span>
            ),
        },
    ];
}

/**
 * Relatórios (Fase 2 — flag `reports`, permissão "Ver relatórios"). Todos os
 * números respeitam a mesma regra de visibilidade da lista de documentos.
 */
export default function ReportsIndex(props: ReportsIndexProps) {
    if (!props.enabled) {
        return (
            <>
                <Head title="Relatórios" />
                <PageHeader
                    title="Relatórios"
                    subtitle="Envios, conclusões e tempo até assinar."
                />
                <Phase2EmptyState
                    title="Relatórios estarão disponíveis na Fase 2"
                    description="Acompanhe documentos enviados, concluídos e recusados por período, usuário, time e etiqueta, com exportação em CSV."
                    ctaHref={envelopesIndex.url()}
                />
            </>
        );
    }

    const { filters, options, report, plan_usage, scope, can } = props;
    const { totals } = report;

    const apply = (next: Partial<Filters>) => {
        router.get(
            reportsIndex.url({ query: { ...filters, ...next } }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const hasFilters =
        !!filters.status ||
        !!filters.folder ||
        !!filters.team ||
        !!filters.creator ||
        !!filters.tag;

    const userColumns = breakdownColumns<UserRow>({
        key: 'user',
        header: 'Usuário',
        width: 'minmax(0,2fr)',
        cell: (row) => (
            <span className="truncate font-semibold">{row.user.name}</span>
        ),
    });

    const teamColumns = breakdownColumns<TeamRow>({
        key: 'team',
        header: 'Time',
        width: 'minmax(0,2fr)',
        cell: (row) => (
            <span
                className={
                    row.team.id
                        ? 'truncate font-semibold'
                        : 'text-muted-foreground truncate'
                }
            >
                {row.team.name}
            </span>
        ),
    });

    return (
        <>
            <Head title="Relatórios" />
            <PageHeader
                title="Relatórios"
                subtitle={`${formatDateMedium(asDay(filters.from))} → ${formatDateMedium(asDay(filters.to))}`}
                actions={
                    can.export && (
                        <Button asChild variant="outline">
                            <a
                                href={reportsExport.url({
                                    query: { ...filters },
                                })}
                            >
                                <Download className="size-[15px]" />
                                Exportar CSV
                            </a>
                        </Button>
                    )
                }
            />

            {!scope.all_envelopes && (
                <div className="bg-primary-soft text-primary flex items-start gap-2 rounded-[10px] p-3 text-[12.5px] leading-[1.5]">
                    <Info className="mt-px size-4 shrink-0" />
                    Os números consideram só os documentos que você pode ver: os
                    que você criou e os das pastas liberadas para você.
                </div>
            )}

            <div className="border-border bg-card shadow-card rounded-xl border">
                <FilterBar
                    trailing={
                        <div className="flex flex-wrap items-center gap-1.5">
                            {PRESETS.map((preset) => (
                                <Button
                                    key={preset.days}
                                    size="xs"
                                    variant="outline"
                                    onClick={() =>
                                        apply({
                                            from: isoDaysAgo(
                                                filters.to,
                                                preset.days,
                                            ),
                                        })
                                    }
                                >
                                    {preset.label}
                                </Button>
                            ))}
                        </div>
                    }
                >
                    <Input
                        type="date"
                        aria-label="De"
                        value={filters.from}
                        max={filters.to}
                        onChange={(e) =>
                            e.target.value && apply({ from: e.target.value })
                        }
                        className="h-[34px] w-[150px] text-[13px]"
                    />
                    <Input
                        type="date"
                        aria-label="Até"
                        value={filters.to}
                        min={filters.from}
                        onChange={(e) =>
                            e.target.value && apply({ to: e.target.value })
                        }
                        className="h-[34px] w-[150px] text-[13px]"
                    />
                    <FilterChip
                        label="Status"
                        value={filters.status}
                        options={options.statuses}
                        onChange={(status) => apply({ status })}
                    />
                    {options.folders.length > 0 && (
                        <FilterChip
                            label="Pasta"
                            value={filters.folder}
                            options={options.folders}
                            onChange={(folder) => apply({ folder })}
                            allLabel="Todas"
                        />
                    )}
                    {options.teams.length > 0 && (
                        <FilterChip
                            label="Time"
                            value={filters.team}
                            options={options.teams}
                            onChange={(team) => apply({ team })}
                        />
                    )}
                    <FilterChip
                        label="Criado por"
                        value={filters.creator}
                        options={options.creators}
                        onChange={(creator) => apply({ creator })}
                    />
                    {options.tags.length > 0 && (
                        <FilterChip
                            label="Etiqueta"
                            value={filters.tag}
                            options={options.tags}
                            onChange={(tag) => apply({ tag })}
                            allLabel="Todas"
                        />
                    )}
                    <ClearFiltersButton
                        visible={hasFilters}
                        onClick={() =>
                            apply({
                                status: null,
                                folder: null,
                                team: null,
                                creator: null,
                                tag: null,
                            })
                        }
                    />
                </FilterBar>
            </div>

            <KpiGrid min={150}>
                <KpiCard
                    variant="compact"
                    label="Enviados"
                    value={formatNumber(totals.sent)}
                    caption={`${formatNumber(totals.pending)} ainda aguardando`}
                    captionTone={totals.pending > 0 ? 'warning' : 'neutral'}
                />
                <KpiCard
                    variant="compact"
                    label="Concluídos"
                    value={formatNumber(totals.completed)}
                    caption="no período"
                    captionTone="success"
                />
                <KpiCard
                    variant="compact"
                    label="Taxa de conclusão"
                    value={
                        totals.completion_rate === null
                            ? '—'
                            : formatPercent(totals.completion_rate)
                    }
                    caption="dos enviados no período"
                />
                <KpiCard
                    variant="compact"
                    label="Tempo médio até concluir"
                    value={formatDuration(totals.avg_minutes_to_complete)}
                    caption="do envio à última assinatura"
                />
                <KpiCard
                    variant="compact"
                    label="Recusados · expirados · cancelados"
                    value={`${formatNumber(totals.refused)} · ${formatNumber(totals.expired)} · ${formatNumber(totals.canceled)}`}
                    captionTone="danger"
                    caption={
                        totals.refused + totals.expired + totals.canceled > 0
                            ? 'encerrados sem assinatura'
                            : 'nenhum no período'
                    }
                />
            </KpiGrid>

            <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                <Heading
                    variant="small"
                    title="Por dia"
                    description="Documentos enviados e concluídos em cada dia do período."
                />
                <SeriesBars series={report.series} />
            </div>

            <div className="flex flex-wrap items-start gap-4">
                <div className="border-border bg-card shadow-card min-w-0 flex-[1.4_1_420px] rounded-xl border">
                    <div className="px-5 pt-[18px] pb-3">
                        <Heading
                            variant="small"
                            title="Por usuário"
                            description="Quem criou o documento."
                        />
                    </div>
                    <DataTable
                        columns={userColumns}
                        rows={report.by_user}
                        rowKey={(row) => row.user.id}
                        minWidth={560}
                        empty={
                            <EmptyState
                                variant="inline"
                                title="Nenhum documento no período."
                            />
                        }
                    />
                </div>

                <div className="flex min-w-0 flex-[1_1_340px] flex-col gap-4">
                    {report.by_team.length > 0 && (
                        <div className="border-border bg-card shadow-card rounded-xl border">
                            <div className="px-5 pt-[18px] pb-3">
                                <Heading
                                    variant="small"
                                    title="Por time"
                                    description="Soma dos criadores de cada time; quem está em dois times conta nos dois."
                                />
                            </div>
                            <DataTable
                                columns={teamColumns}
                                rows={report.by_team}
                                rowKey={(row) => row.team.id ?? 'none'}
                                minWidth={520}
                            />
                        </div>
                    )}

                    {plan_usage && (
                        <div className="border-border bg-card shadow-card flex flex-col gap-3.5 rounded-xl border p-5">
                            <Heading
                                variant="small"
                                title="Uso do plano"
                                description={`Plano ${plan_usage.plan_name}${plan_usage.cycle_end ? ` · ciclo até ${formatDateMedium(plan_usage.cycle_end)}` : ''}`}
                            />
                            <ProgressMeter
                                label="Documentos no ciclo"
                                used={plan_usage.cycle_used}
                                limit={plan_usage.cycle_limit}
                                size="sm"
                            />
                            <p className="text-muted-foreground text-[12.5px]">
                                {formatNumber(
                                    plan_usage.envelopes_committed_in_period,
                                )}{' '}
                                {plan_usage.envelopes_committed_in_period === 1
                                    ? 'documento consumido'
                                    : 'documentos consumidos'}{' '}
                                da cota no período selecionado (toda a conta).
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [{ title: 'Relatórios', href: reportsIndex() }],
};
