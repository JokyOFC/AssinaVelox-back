import type { ReactNode } from 'react';
import { usagePercent } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * Medidor de uso (DESIGN §4.22): label + "**148** / 500" + trilho 8px.
 * Indicador âmbar quando ≥ 90 %. Limite nulo = ilimitado (sem barra).
 */
export function ProgressMeter({
    label,
    used,
    limit,
    usedLabel,
    limitLabel,
    size = 'default',
    className,
    hint,
}: {
    label: ReactNode;
    used: number;
    limit: number | null;
    usedLabel?: string;
    limitLabel?: string;
    size?: 'default' | 'sm';
    className?: string;
    hint?: ReactNode;
}) {
    const pct = usagePercent(used, limit);
    const warn = pct !== null && pct >= 90;

    return (
        <div className={cn('flex flex-col', className)}>
            <div className="text-text-secondary flex justify-between gap-3 text-[12.5px]">
                <span>{label}</span>
                <span className="tabular">
                    <b className="text-foreground">{usedLabel ?? used}</b>
                    {limit !== null
                        ? ` / ${limitLabel ?? limit}`
                        : ' · ilimitado'}
                </span>
            </div>
            {pct !== null && (
                <div
                    role="progressbar"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={pct}
                    className={cn(
                        'bg-accent mt-1.5 overflow-hidden rounded-full',
                        size === 'sm' ? 'h-[5px]' : 'h-2',
                    )}
                >
                    <div
                        className={cn(
                            'h-full rounded-full transition-[width]',
                            warn ? 'bg-warning-solid' : 'bg-primary',
                        )}
                        style={{ width: `${pct}%` }}
                    />
                </div>
            )}
            {hint && (
                <p className="text-muted-foreground mt-1 text-[12px]">{hint}</p>
            )}
        </div>
    );
}
