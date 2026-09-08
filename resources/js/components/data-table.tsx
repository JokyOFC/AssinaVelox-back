import type { ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

/**
 * Tabela em CSS grid (DESIGN §4.8) — fiel às células de duas linhas dos mocks.
 * - Header 38px, `bg-background`, 12px/600 `muted-foreground` (uppercase 10.5px opcional).
 * - Linhas ≥ 52px, divisórias `muted`, hover `row-hover`, selecionada `accent-subtle`.
 * - Contêiner com `overflow-x-auto` para telas estreitas.
 */

export type DataTableColumn<T> = {
    key: string;
    header: ReactNode;
    /** Fração/valor do grid-template-columns, ex.: "minmax(0,2.4fr)" | "1fr" | "48px". */
    width: string;
    cell: (row: T, index: number) => ReactNode;
    align?: 'left' | 'right' | 'center';
    className?: string;
    headerClassName?: string;
};

export function DataTable<T>({
    columns,
    rows,
    rowKey,
    selectable = false,
    selected,
    onSelectedChange,
    onRowClick,
    loading = false,
    skeletonRows = 6,
    empty,
    minWidth = 720,
    dense = false,
    uppercaseHeader = false,
    className,
}: {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string;
    selectable?: boolean;
    selected?: Set<string>;
    onSelectedChange?: (selected: Set<string>) => void;
    onRowClick?: (row: T) => void;
    loading?: boolean;
    skeletonRows?: number;
    empty?: ReactNode;
    /** Largura mínima (px) antes de rolar horizontalmente. */
    minWidth?: number;
    dense?: boolean;
    uppercaseHeader?: boolean;
    className?: string;
}) {
    const selectedSet = selected ?? new Set<string>();
    const allKeys = rows.map(rowKey);
    const allSelected =
        allKeys.length > 0 && allKeys.every((key) => selectedSet.has(key));
    const someSelected = !allSelected && allKeys.some((key) => selectedSet.has(key));

    const template = [
        selectable ? '36px' : null,
        ...columns.map((column) => column.width),
    ]
        .filter(Boolean)
        .join(' ');

    const toggleAll = (checked: boolean) => {
        const next = new Set(selectedSet);

        allKeys.forEach((key) => {
            if (checked) {
                next.add(key);
            } else {
                next.delete(key);
            }
        });

        onSelectedChange?.(next);
    };

    const toggleRow = (key: string, checked: boolean) => {
        const next = new Set(selectedSet);

        if (checked) {
            next.add(key);
        } else {
            next.delete(key);
        }

        onSelectedChange?.(next);
    };

    const alignClass = (align?: 'left' | 'right' | 'center') =>
        align === 'right'
            ? 'justify-end text-right'
            : align === 'center'
              ? 'justify-center text-center'
              : 'justify-start text-left';

    return (
        <div className={cn('w-full overflow-x-auto', className)}>
            <div role="table" style={{ minWidth }}>
                <div
                    role="row"
                    className={cn(
                        'grid h-[38px] items-center border-y border-muted bg-background px-4 font-semibold text-muted-foreground',
                        uppercaseHeader
                            ? 'text-[10.5px] tracking-[.14em] uppercase'
                            : 'text-[12px]',
                    )}
                    style={{ gridTemplateColumns: template }}
                >
                    {selectable && (
                        <div role="columnheader" className="flex items-center">
                            <Checkbox
                                aria-label="Selecionar todos"
                                checked={
                                    allSelected
                                        ? true
                                        : someSelected
                                          ? 'indeterminate'
                                          : false
                                }
                                onCheckedChange={(checked) =>
                                    toggleAll(checked === true)
                                }
                            />
                        </div>
                    )}
                    {columns.map((column) => (
                        <div
                            role="columnheader"
                            key={column.key}
                            className={cn(
                                'flex items-center truncate pr-3 last:pr-0',
                                alignClass(column.align),
                                column.headerClassName,
                            )}
                        >
                            {column.header}
                        </div>
                    ))}
                </div>

                {loading &&
                    Array.from({ length: skeletonRows }, (_, index) => (
                        <div
                            key={`skeleton-${index}`}
                            role="row"
                            className="grid items-center border-b border-muted px-4 py-[13px]"
                            style={{ gridTemplateColumns: template }}
                        >
                            {selectable && <Skeleton className="size-4 rounded" />}
                            {columns.map((column) => (
                                <div key={column.key} className="pr-3">
                                    <Skeleton className="h-3.5 w-3/4 rounded" />
                                </div>
                            ))}
                        </div>
                    ))}

                {!loading && rows.length === 0 && (
                    <div role="row">
                        {empty ?? (
                            <p className="px-5 py-12 text-center text-[13.5px] text-muted-foreground">
                                Nenhum registro encontrado.
                            </p>
                        )}
                    </div>
                )}

                {!loading &&
                    rows.map((row, index) => {
                        const key = rowKey(row);
                        const isSelected = selectedSet.has(key);

                        return (
                            <div
                                role="row"
                                key={key}
                                aria-selected={isSelected}
                                onClick={onRowClick ? () => onRowClick(row) : undefined}
                                className={cn(
                                    'grid min-h-[52px] items-center border-b border-muted px-4 text-[13.5px] transition-colors last:border-b-0 hover:bg-row-hover',
                                    dense ? 'py-[9px]' : 'py-[10px]',
                                    isSelected && 'bg-accent-subtle hover:bg-accent-subtle',
                                    onRowClick && 'cursor-pointer',
                                )}
                                style={{ gridTemplateColumns: template }}
                            >
                                {selectable && (
                                    <div
                                        role="cell"
                                        className="flex items-center"
                                        onClick={(event) => event.stopPropagation()}
                                    >
                                        <Checkbox
                                            aria-label="Selecionar linha"
                                            checked={isSelected}
                                            onCheckedChange={(checked) =>
                                                toggleRow(key, checked === true)
                                            }
                                        />
                                    </div>
                                )}
                                {columns.map((column) => (
                                    <div
                                        role="cell"
                                        key={column.key}
                                        className={cn(
                                            'flex min-w-0 items-center pr-3 last:pr-0',
                                            alignClass(column.align),
                                            column.className,
                                        )}
                                    >
                                        {column.cell(row, index)}
                                    </div>
                                ))}
                            </div>
                        );
                    })}
            </div>
        </div>
    );
}

/** Célula "título" com tile de ícone 36px + título + meta (DESIGN §4.8). */
export function TitleCell({
    icon,
    title,
    meta,
    href,
    onClick,
}: {
    icon?: ReactNode;
    title: ReactNode;
    meta?: ReactNode;
    href?: string;
    onClick?: () => void;
}) {
    const content = (
        <span className="block truncate font-semibold text-foreground hover:text-primary">
            {title}
        </span>
    );

    return (
        <div className="flex min-w-0 items-center gap-3">
            {icon && (
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-subtle text-primary">
                    {icon}
                </span>
            )}
            <span className="min-w-0 flex-1">
                {href ? (
                    <a
                        href={href}
                        onClick={(event) => {
                            if (onClick) {
                                event.preventDefault();
                                onClick();
                            }
                        }}
                    >
                        {content}
                    </a>
                ) : (
                    content
                )}
                {meta && (
                    <span className="block truncate text-[12px] text-muted-foreground tabular">
                        {meta}
                    </span>
                )}
            </span>
        </div>
    );
}

/** Célula secundária padrão: 13px `text-secondary`. */
export function SecondaryCell({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <span className={cn('truncate text-[13px] text-text-secondary', className)}>
            {children}
        </span>
    );
}

/** Barra de ações em lote (DESIGN §1.1 primary-soft) exibida acima da tabela. */
export function BulkActionBar({
    count,
    onClear,
    children,
}: {
    count: number;
    onClear: () => void;
    children: ReactNode;
}) {
    if (count === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-2 border-b border-primary-soft-border bg-primary-soft px-4 py-2 text-[13px]">
            <span className="font-semibold text-primary">
                {count} {count === 1 ? 'selecionado' : 'selecionados'}
            </span>
            <button
                type="button"
                onClick={onClear}
                className="text-[12.5px] font-semibold text-primary hover:underline"
            >
                Limpar seleção
            </button>
            <div className="ml-auto flex flex-wrap items-center gap-2">
                {children}
            </div>
        </div>
    );
}
