import type { ReactNode } from 'react';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AuditEvent, AuditEventKind } from '@/types';

const KIND_CLASSES: Record<AuditEventKind, string> = {
    info: 'bg-primary-soft text-primary',
    ok: 'bg-success-bg text-success',
    warn: 'bg-warning-bg text-warning',
};

/**
 * Trilha de auditoria (DESIGN §4.18): marcador numerado 20px colorido por
 * `kind`, conector vertical, título 13.5px e meta 12px.
 */
export function Timeline({
    events,
    footer,
    emptyText = 'Nenhum evento registrado ainda.',
    className,
}: {
    events: Pick<AuditEvent, 'id' | 'kind' | 'title' | 'meta' | 'occurred_at'>[];
    footer?: ReactNode;
    emptyText?: string;
    className?: string;
}) {
    if (events.length === 0) {
        return (
            <p className="px-1 py-6 text-center text-[13.5px] text-muted-foreground">
                {emptyText}
            </p>
        );
    }

    return (
        <div className={cn('flex flex-col', className)}>
            <ol className="flex flex-col">
                {events.map((event, index) => {
                    const last = index === events.length - 1;

                    return (
                        <li
                            key={event.id}
                            className="grid grid-cols-[20px_1fr] gap-3"
                        >
                            <div className="flex flex-col items-center">
                                <span
                                    className={cn(
                                        'flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold',
                                        KIND_CLASSES[event.kind],
                                    )}
                                >
                                    {index + 1}
                                </span>
                                {!last && (
                                    <span className="my-1 w-0.5 flex-1 bg-accent" />
                                )}
                            </div>
                            <div className={cn(!last && 'pb-4')}>
                                <p className="text-[13.5px] leading-[1.3] font-semibold">
                                    {event.title}
                                </p>
                                <p className="mt-[3px] text-[12px] text-muted-foreground tabular">
                                    {formatDateTime(event.occurred_at)}
                                    {event.meta && ` · ${event.meta}`}
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ol>
            {footer}
        </div>
    );
}

/** Caixa neutra para hash SHA-256 (DESIGN §4.11). */
export function HashBox({
    label,
    value,
    action,
    className,
}: {
    label: string;
    value: string | null | undefined;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'rounded-lg border border-border bg-sidebar p-3 text-[12px]',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="font-semibold text-text-secondary">{label}</span>
                {action}
            </div>
            <code className="mt-1 block font-mono break-all text-foreground">
                {value ?? '—'}
            </code>
        </div>
    );
}
