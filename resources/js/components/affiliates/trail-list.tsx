import { formatDateTime } from '@/lib/format';
import { formatRateBp } from './affiliate-badges';
import type { TrailEntry } from './types';

function detail(entry: TrailEntry): string | null {
    const p = entry.payload;

    if (entry.action === 'affiliate.rate_changed') {
        return `${formatRateBp(Number(p.from_bp))} → ${formatRateBp(Number(p.to_bp))} · ${typeof p.reason === 'string' ? p.reason : ''}`;
    }

    if (typeof p.reason === 'string') {
        return p.reason;
    }

    if (typeof p.note === 'string') {
        return p.note;
    }

    if (typeof p.external_reference === 'string') {
        return `Referência externa: ${p.external_reference}`;
    }

    return null;
}

/** Trilha append-only do programa (quem, quando, o quê). */
export function TrailList({ entries }: { entries: TrailEntry[] }) {
    if (entries.length === 0) {
        return (
            <p className="text-muted-foreground text-[13px]">
                Nenhum evento registrado.
            </p>
        );
    }

    return (
        <ol className="divide-border divide-y">
            {entries.map((entry) => {
                const extra = detail(entry);

                return (
                    <li key={entry.id} className="flex flex-col gap-0.5 py-2.5">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <span className="text-[13.5px] font-semibold">
                                {entry.label}
                            </span>
                            <span className="text-muted-foreground text-[12px]">
                                {formatDateTime(entry.occurred_at)}
                            </span>
                        </div>
                        <span className="text-muted-foreground text-[12.5px]">
                            {entry.actor ?? 'Sistema'}
                            {extra ? ` · ${extra}` : ''}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
