import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type BillingCalloutTone =
    | 'info'
    | 'warning'
    | 'danger'
    | 'success'
    | 'neutral';

const TONE: Record<BillingCalloutTone, string> = {
    info: 'border-info-border bg-info-bg',
    warning: 'border-warning-border bg-warning-bg',
    danger: 'border-danger-border bg-danger-bg',
    success: 'border-success-border bg-success-bg',
    neutral: 'border-border bg-sidebar',
};

const TITLE_TONE: Record<BillingCalloutTone, string> = {
    info: 'text-info',
    warning: 'text-warning',
    danger: 'text-danger',
    success: 'text-success',
    neutral: 'text-foreground',
};

/**
 * Faixa de aviso das telas de cobrança (DESIGN §4.17 / §5.5): ícone, título
 * curto, explicação e ações à direita. O corpo mantém o cinza de texto para
 * continuar legível dentro dos fundos coloridos.
 */
export function BillingCallout({
    tone = 'info',
    icon: Icon,
    title,
    children,
    actions,
    role = 'status',
    className,
}: {
    tone?: BillingCalloutTone;
    icon?: LucideIcon;
    title: ReactNode;
    children?: ReactNode;
    actions?: ReactNode;
    role?: 'status' | 'alert';
    className?: string;
}) {
    return (
        <div
            role={role}
            className={cn(
                'flex flex-wrap items-start gap-3 rounded-[10px] border p-3.5',
                TONE[tone],
                className,
            )}
        >
            {Icon && (
                <Icon
                    aria-hidden
                    className={cn('mt-px size-4 shrink-0', TITLE_TONE[tone])}
                />
            )}
            <div className="min-w-0 flex-1">
                <p
                    className={cn(
                        'text-[13px] font-semibold',
                        TITLE_TONE[tone],
                    )}
                >
                    {title}
                </p>
                {children && (
                    <div className="text-text-secondary mt-1 text-[12.5px] leading-[1.55]">
                        {children}
                    </div>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
