import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type KpiDelta = {
    label: string;
    tone: 'success' | 'warning' | 'danger' | 'neutral';
    direction?: 'up' | 'down';
};

/**
 * Stat tile (DESIGN §4.10).
 * - `variant="default"` (Dashboard): valor 30px, badge de delta ao lado do label.
 * - `variant="compact"` (Assinaturas, Admin): valor 26px, caption colorida.
 */
export function KpiCard({
    label,
    value,
    unit,
    delta,
    caption,
    captionTone = 'neutral',
    variant = 'default',
    className,
}: {
    label: string;
    value: ReactNode;
    unit?: string;
    delta?: KpiDelta | null;
    caption?: ReactNode;
    captionTone?: 'success' | 'warning' | 'danger' | 'neutral';
    variant?: 'default' | 'compact';
    className?: string;
}) {
    const captionColor = {
        success: 'text-success font-semibold',
        warning: 'text-warning font-semibold',
        danger: 'text-danger font-semibold',
        neutral: 'text-muted-foreground',
    }[captionTone];

    if (variant === 'compact') {
        return (
            <div
                className={cn(
                    'border-border bg-card shadow-card flex flex-col gap-1.5 rounded-xl border px-[18px] py-4',
                    className,
                )}
            >
                <span className="text-text-secondary text-[12.5px] font-medium">
                    {label}
                </span>
                <span className="tabular text-[26px] leading-none font-bold tracking-[-.02em]">
                    {value}
                    {unit && (
                        <span className="text-muted-foreground ml-1 text-[14px] font-semibold">
                            {unit}
                        </span>
                    )}
                </span>
                {caption && (
                    <span className={cn('text-[12px]', captionColor)}>
                        {caption}
                    </span>
                )}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'border-border bg-card shadow-card flex flex-col gap-2.5 rounded-xl border p-5 pb-[18px]',
                className,
            )}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <span className="text-text-secondary text-[13px] font-medium">
                    {label}
                </span>
                {delta && (
                    <Badge
                        variant={delta.tone}
                        className="gap-1 px-[7px] text-[11.5px]"
                    >
                        {delta.direction === 'up' && (
                            <ArrowUpRight className="size-[11px]" />
                        )}
                        {delta.direction === 'down' && (
                            <ArrowDownRight className="size-[11px]" />
                        )}
                        {delta.label}
                    </Badge>
                )}
            </div>
            <div className="tabular text-[30px] leading-none font-bold tracking-[-.02em]">
                {value}
                {unit && (
                    <span className="text-muted-foreground ml-1 text-[16px] font-semibold">
                        {unit}
                    </span>
                )}
            </div>
            {caption && (
                <div className={cn('text-[12.5px]', captionColor)}>
                    {caption}
                </div>
            )}
        </div>
    );
}

/** Grid responsivo de KPIs: `auto-fit, minmax(140px|150px, 1fr)`. */
export function KpiGrid({
    children,
    min = 140,
    className,
}: {
    children: ReactNode;
    min?: 140 | 150;
    className?: string;
}) {
    return (
        <div
            className={cn('grid gap-4', className)}
            style={{
                gridTemplateColumns: `repeat(auto-fit, minmax(${min}px, 1fr))`,
            }}
        >
            {children}
        </div>
    );
}
