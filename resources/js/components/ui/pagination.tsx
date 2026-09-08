import { Link } from '@inertiajs/react';
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    MoreHorizontalIcon,
} from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

function Pagination({ className, ...props }: React.ComponentProps<'nav'>) {
    return (
        <nav
            role="navigation"
            aria-label="Paginação"
            data-slot="pagination"
            className={cn('flex items-center justify-center', className)}
            {...props}
        />
    );
}

function PaginationContent({
    className,
    ...props
}: React.ComponentProps<'ul'>) {
    return (
        <ul
            data-slot="pagination-content"
            className={cn('flex flex-row items-center gap-1.5', className)}
            {...props}
        />
    );
}

function PaginationItem({ ...props }: React.ComponentProps<'li'>) {
    return <li data-slot="pagination-item" {...props} />;
}

type PaginationLinkProps = {
    isActive?: boolean;
    href: string | null;
    disabled?: boolean;
    className?: string;
    children?: React.ReactNode;
    'aria-label'?: string;
};

/**
 * Página do rodapé de tabela (DESIGN §4.9): 30px, ativa em navy sólido,
 * demais em branco com borda `input`; desabilitadas com ícone `#c9d4e6`.
 */
function PaginationLink({
    className,
    isActive,
    href,
    disabled,
    children,
    ...props
}: PaginationLinkProps) {
    const ariaLabel = props['aria-label'];
    const classes = cn(
        'inline-flex h-[30px] min-w-[30px] items-center justify-center rounded-md px-2 text-[12.5px] font-semibold tabular transition-colors',
        isActive
            ? 'bg-navy text-white'
            : 'border border-input bg-white text-foreground hover:bg-accent-subtle',
        (disabled || !href) && !isActive && 'pointer-events-none text-border-dashed',
        className,
    );

    if (!href || disabled) {
        return (
            <span
                aria-current={isActive ? 'page' : undefined}
                aria-disabled={!isActive}
                aria-label={ariaLabel}
                className={classes}
            >
                {children}
            </span>
        );
    }

    return (
        <Link
            aria-current={isActive ? 'page' : undefined}
            data-slot="pagination-link"
            data-active={isActive}
            href={href}
            preserveScroll
            preserveState
            className={classes}
            aria-label={ariaLabel}
        >
            {children}
        </Link>
    );
}

function PaginationPrevious({
    className,
    ...props
}: PaginationLinkProps) {
    return (
        <PaginationLink
            aria-label="Página anterior"
            className={cn('px-0', className)}
            {...props}
        >
            <ChevronLeftIcon className="size-4" />
        </PaginationLink>
    );
}

function PaginationNext({ className, ...props }: PaginationLinkProps) {
    return (
        <PaginationLink
            aria-label="Próxima página"
            className={cn('px-0', className)}
            {...props}
        >
            <ChevronRightIcon className="size-4" />
        </PaginationLink>
    );
}

function PaginationEllipsis({
    className,
    ...props
}: React.ComponentProps<'span'>) {
    return (
        <span
            aria-hidden
            data-slot="pagination-ellipsis"
            className={cn(
                'flex size-[30px] items-center justify-center text-muted-foreground',
                className,
            )}
            {...props}
        >
            <MoreHorizontalIcon className="size-4" />
            <span className="sr-only">Mais páginas</span>
        </span>
    );
}

export {
    Pagination,
    PaginationContent,
    PaginationLink,
    PaginationItem,
    PaginationPrevious,
    PaginationNext,
    PaginationEllipsis,
};
