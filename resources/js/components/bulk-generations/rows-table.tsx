import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { SelectableChip } from '@/components/filter-bar';
import { Button } from '@/components/ui/button';
import { edit as envelopeEdit, show as envelopeShow } from '@/routes/envelopes';
import { show } from '@/routes/bulk_generations';
import { BulkRowBadge } from './status-badge';
import type { BulkRow } from './types';

export interface RowsPage {
    filter: 'problems' | 'all';
    current_page: number;
    last_page: number;
    total: number;
    data: BulkRow[];
}

/**
 * Relatório por linha: erros por coluna (pré-validação), falhas e o documento
 * gerado. Nunca mostra o valor digitado — só a coluna e a mensagem.
 */
export function RowsTable({
    batchId,
    rows,
}: {
    batchId: string;
    rows: RowsPage;
}) {
    const go = (filter: RowsPage['filter'], page = 1) =>
        router.get(
            show.url(batchId, { query: { linhas: filter, pagina: page } }),
            {},
            { preserveScroll: true, preserveState: true, only: ['rows'] },
        );

    return (
        <section className="border-border bg-card overflow-hidden rounded-xl border">
            <div className="border-border flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3">
                <h2 className="text-[14px] font-semibold">
                    Linhas da planilha
                </h2>
                <div className="flex gap-2">
                    <SelectableChip
                        selected={rows.filter === 'problems'}
                        onClick={() => go('problems')}
                    >
                        Com problema
                    </SelectableChip>
                    <SelectableChip
                        selected={rows.filter === 'all'}
                        onClick={() => go('all')}
                    >
                        Todas
                    </SelectableChip>
                </div>
            </div>

            {rows.data.length === 0 ? (
                <p className="text-muted-foreground px-5 py-10 text-center text-[13px]">
                    {rows.filter === 'problems'
                        ? 'Nenhuma linha com problema.'
                        : 'Nenhuma linha.'}
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="bg-muted/40 text-muted-foreground text-left text-[11.5px] tracking-wide uppercase">
                            <tr>
                                <th className="w-16 px-5 py-2 font-semibold">
                                    Linha
                                </th>
                                <th className="w-40 px-3 py-2 font-semibold">
                                    Situação
                                </th>
                                <th className="px-3 py-2 font-semibold">
                                    Detalhes
                                </th>
                                <th className="w-48 px-5 py-2 font-semibold">
                                    Documento
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-border divide-y">
                            {rows.data.map((row) => (
                                <tr key={row.line} className="align-top">
                                    <td className="tabular px-5 py-2.5">
                                        {row.line}
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <BulkRowBadge row={row} />
                                    </td>
                                    <td className="text-text-secondary px-3 py-2.5">
                                        <RowDetails row={row} />
                                    </td>
                                    <td className="px-5 py-2.5">
                                        {row.envelope ? (
                                            <Link
                                                href={
                                                    row.envelope.draft
                                                        ? envelopeEdit(
                                                              row.envelope.id,
                                                          )
                                                        : envelopeShow(
                                                              row.envelope.id,
                                                          )
                                                }
                                                className="text-primary hover:underline"
                                            >
                                                {row.envelope.display_code}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {rows.last_page > 1 && (
                <div className="border-border text-text-secondary flex items-center justify-end gap-2 border-t px-5 py-2.5 text-[12.5px]">
                    <span>
                        Página {rows.current_page} de {rows.last_page}
                    </span>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        aria-label="Página anterior"
                        disabled={rows.current_page <= 1}
                        onClick={() => go(rows.filter, rows.current_page - 1)}
                    >
                        <ChevronLeft />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        aria-label="Próxima página"
                        disabled={rows.current_page >= rows.last_page}
                        onClick={() => go(rows.filter, rows.current_page + 1)}
                    >
                        <ChevronRight />
                    </Button>
                </div>
            )}
        </section>
    );
}

function RowDetails({ row }: { row: BulkRow }) {
    if (row.errors.length > 0) {
        return (
            <ul className="space-y-0.5">
                {row.errors.map((error, index) => (
                    <li key={index}>
                        {error.header && (
                            <span className="text-foreground font-medium">
                                {error.header}:{' '}
                            </span>
                        )}
                        {error.message}
                    </li>
                ))}
            </ul>
        );
    }

    if (row.error) {
        return <span>{row.error}</span>;
    }

    if (row.outcome_message) {
        return <span>{row.outcome_message}</span>;
    }

    if (row.envelope) {
        return <span className="line-clamp-1">{row.envelope.title}</span>;
    }

    return <span className="text-muted-foreground">—</span>;
}
