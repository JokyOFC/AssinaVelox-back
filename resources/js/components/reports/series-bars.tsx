import { formatDayMonth, formatNumber } from '@/lib/format';

/** Data sem hora ("2026-09-10") lida como meio-dia local: sem virar o dia anterior no fuso. */
export function asDay(date: string): string {
    return `${date}T12:00:00`;
}

export interface SeriesPoint {
    date: string;
    sent: number;
    completed: number;
}

/**
 * Série diária em barras pareadas (enviados × concluídos), em CSS puro — sem
 * biblioteca de gráfico. Cada dia tem `title` com os valores exatos.
 */
export function SeriesBars({ series }: { series: SeriesPoint[] }) {
    const max = Math.max(
        1,
        ...series.map((p) => Math.max(p.sent, p.completed)),
    );
    const labelEvery = Math.max(1, Math.ceil(series.length / 8));

    return (
        <div className="flex flex-col gap-2">
            <div className="text-muted-foreground flex items-center gap-4 text-[12px]">
                <span className="inline-flex items-center gap-1.5">
                    <span className="bg-primary inline-block size-2.5 rounded-sm" />
                    Enviados
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span className="bg-success-solid inline-block size-2.5 rounded-sm" />
                    Concluídos
                </span>
            </div>
            <div className="overflow-x-auto">
                <div
                    className="flex h-[140px] min-w-full items-end gap-[3px]"
                    style={{ width: `${Math.max(series.length * 14, 280)}px` }}
                    role="img"
                    aria-label="Documentos enviados e concluídos por dia"
                >
                    {series.map((point) => (
                        <div
                            key={point.date}
                            className="flex h-full min-w-[11px] flex-1 items-end gap-px"
                            title={`${formatDayMonth(asDay(point.date))} · ${formatNumber(point.sent)} enviados · ${formatNumber(point.completed)} concluídos`}
                        >
                            <span
                                className="bg-primary w-1/2 rounded-t-[2px]"
                                style={{
                                    height: `${(point.sent / max) * 100}%`,
                                    minHeight: point.sent > 0 ? 2 : 0,
                                }}
                            />
                            <span
                                className="bg-success-solid w-1/2 rounded-t-[2px]"
                                style={{
                                    height: `${(point.completed / max) * 100}%`,
                                    minHeight: point.completed > 0 ? 2 : 0,
                                }}
                            />
                        </div>
                    ))}
                </div>
                <div
                    className="text-muted-foreground mt-1 flex min-w-full gap-[3px] text-[10.5px]"
                    style={{ width: `${Math.max(series.length * 14, 280)}px` }}
                >
                    {series.map((point, index) => (
                        <span
                            key={point.date}
                            className="min-w-[11px] flex-1 overflow-visible whitespace-nowrap"
                        >
                            {index % labelEvery === 0
                                ? formatDayMonth(asDay(point.date))
                                : ''}
                        </span>
                    ))}
                </div>
            </div>
        </div>
    );
}
