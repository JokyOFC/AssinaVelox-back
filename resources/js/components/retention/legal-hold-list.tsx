import { Lock, Unlock } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/format';
import type { LegalHoldRow } from './types';

function holdState(hold: LegalHoldRow): {
    label: string;
    variant: 'info' | 'neutral' | 'draft';
} {
    if (hold.active) {
        return { label: 'Ativa', variant: 'info' };
    }

    if (hold.released_at) {
        return { label: 'Liberada', variant: 'neutral' };
    }

    return { label: 'Encerrada pela data', variant: 'draft' };
}

/**
 * Lista de preservações (ativas primeiro) com quem, motivo, desde quando e até
 * quando; as liberadas mostram quem liberou e por quê.
 */
export function LegalHoldList({
    holds,
    onRelease,
    emptyText = 'Nenhuma preservação registrada.',
}: {
    holds: LegalHoldRow[];
    onRelease?: (hold: LegalHoldRow) => void;
    emptyText?: string;
}) {
    if (holds.length === 0) {
        return <p className="text-muted-foreground text-[13px]">{emptyText}</p>;
    }

    return (
        <ul className="divide-border flex flex-col divide-y">
            {holds.map((hold) => {
                const state = holdState(hold);

                return (
                    <li
                        key={hold.id}
                        className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div className="flex min-w-0 flex-[1_1_320px] flex-col gap-1">
                            <div className="flex flex-wrap items-center gap-2">
                                {hold.active ? (
                                    <Lock
                                        aria-hidden
                                        className="text-info size-4"
                                    />
                                ) : (
                                    <Unlock
                                        aria-hidden
                                        className="text-muted-foreground size-4"
                                    />
                                )}
                                <span className="text-[13.5px] font-semibold">
                                    {hold.subject}
                                </span>
                                <Badge variant={state.variant}>
                                    {state.label}
                                </Badge>
                                <span className="text-muted-foreground text-[12px]">
                                    {hold.scope_label}
                                </span>
                            </div>
                            <p className="text-[13px] break-words">
                                {hold.reason}
                            </p>
                            <p className="text-muted-foreground text-[12px]">
                                Desde {formatDateTime(hold.starts_at)}
                                {hold.created_by
                                    ? ` por ${hold.created_by}`
                                    : ''}
                                {hold.ends_at
                                    ? ` · até ${formatDate(hold.ends_at)}`
                                    : ' · sem data final'}
                            </p>
                            {hold.released_at && (
                                <p className="text-muted-foreground text-[12px]">
                                    Liberada em{' '}
                                    {formatDateTime(hold.released_at)}
                                    {hold.released_by
                                        ? ` por ${hold.released_by}`
                                        : ''}
                                    {hold.release_reason
                                        ? ` — ${hold.release_reason}`
                                        : ''}
                                </p>
                            )}
                        </div>

                        {hold.can_release && onRelease && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => onRelease(hold)}
                            >
                                Liberar
                            </Button>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
