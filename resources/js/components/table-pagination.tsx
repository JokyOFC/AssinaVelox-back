import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

const PER_PAGE_OPTIONS = [10, 25, 50];

function pageNumbers(current: number, last: number): (number | 'ellipsis')[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }

    const pages = new Set<number>([1, last, current, current - 1, current + 1]);

    if (current <= 3) {
        [2, 3, 4].forEach((p) => pages.add(p));
    }

    if (current >= last - 2) {
        [last - 1, last - 2, last - 3].forEach((p) => pages.add(p));
    }

    const sorted = [...pages]
        .filter((p) => p >= 1 && p <= last)
        .sort((a, b) => a - b);
    const result: (number | 'ellipsis')[] = [];

    sorted.forEach((page, index) => {
        if (index > 0 && page - sorted[index - 1] > 1) {
            result.push('ellipsis');
        }

        result.push(page);
    });

    return result;
}

function pageUrl(meta: Paginated<unknown>['meta'], page: number): string {
    const link = meta.links.find((l) => l.label === String(page));

    if (link?.url) {
        return link.url;
    }

    const base = new URL(meta.path, window.location.origin);
    const params = new URLSearchParams(window.location.search);
    params.set('page', String(page));

    return `${base.pathname}?${params.toString()}`;
}

/**
 * Rodapé de tabela (DESIGN §4.9): "Mostrando X–Y de N {entidade}" à esquerda,
 * "Linhas por página" + pager à direita. Navegação via query-string preservando estado.
 */
export function TablePagination<T>({
    paginated,
    entity = 'registros',
    entitySingular,
    showPerPage = true,
    onPerPageChange,
    extra,
    className,
}: {
    paginated: Paginated<T>;
    entity?: string;
    entitySingular?: string;
    showPerPage?: boolean;
    onPerPageChange?: (perPage: number) => void;
    /** Slot à esquerda (ex.: link "Abrir lista completa"). */
    extra?: ReactNode;
    className?: string;
}) {
    const { meta, links } = paginated;
    const total = meta.total;
    const noun = total === 1 && entitySingular ? entitySingular : entity;

    const handlePerPage = (value: string) => {
        const perPage = Number(value);

        if (onPerPageChange) {
            onPerPageChange(perPage);

            return;
        }

        const params = new URLSearchParams(window.location.search);
        params.set('per_page', String(perPage));
        params.delete('page');

        router.get(
            `${window.location.pathname}?${params.toString()}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <div
            className={cn(
                'text-muted-foreground flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-[12.5px]',
                className,
            )}
        >
            <div className="flex items-center gap-3">
                <span className="tabular">
                    {total === 0
                        ? `Nenhum ${entitySingular ?? entity}`
                        : meta.from !== null &&
                            meta.to !== null &&
                            meta.from !== meta.to
                          ? `Mostrando ${meta.from}–${meta.to} de ${formatNumber(total)} ${noun}`
                          : `Mostrando ${meta.to ?? meta.from ?? 0} de ${formatNumber(total)} ${noun}`}
                </span>
                {extra}
            </div>

            {(meta.last_page > 1 || showPerPage) && (
                <div className="flex flex-wrap items-center gap-3">
                    {showPerPage && (
                        <div className="flex items-center gap-2">
                            <span>Linhas por página</span>
                            <Select
                                value={String(meta.per_page)}
                                onValueChange={handlePerPage}
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="text-foreground h-[30px] w-[64px] rounded-md px-2 text-[12.5px] font-semibold"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {PER_PAGE_OPTIONS.map((option) => (
                                        <SelectItem
                                            key={option}
                                            value={String(option)}
                                        >
                                            {option}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {meta.last_page > 1 && (
                        <Pagination className="justify-end">
                            <PaginationContent>
                                <PaginationItem>
                                    <PaginationPrevious
                                        href={links.prev}
                                        disabled={!links.prev}
                                    />
                                </PaginationItem>
                                {pageNumbers(
                                    meta.current_page,
                                    meta.last_page,
                                ).map((page, index) =>
                                    page === 'ellipsis' ? (
                                        <PaginationItem key={`e-${index}`}>
                                            <PaginationEllipsis />
                                        </PaginationItem>
                                    ) : (
                                        <PaginationItem key={page}>
                                            <PaginationLink
                                                href={pageUrl(meta, page)}
                                                isActive={
                                                    page === meta.current_page
                                                }
                                            >
                                                {page}
                                            </PaginationLink>
                                        </PaginationItem>
                                    ),
                                )}
                                <PaginationItem>
                                    <PaginationNext
                                        href={links.next}
                                        disabled={!links.next}
                                    />
                                </PaginationItem>
                            </PaginationContent>
                        </Pagination>
                    )}
                </div>
            )}
        </div>
    );
}
