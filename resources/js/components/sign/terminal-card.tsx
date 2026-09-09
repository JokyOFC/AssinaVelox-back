import { Ban, CalendarX2, Link2, XCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type TerminalTone = 'neutral' | 'danger';

/**
 * Cartão dos estados terminais da página pública (ROUTES §3.4).
 * Os títulos e textos são os do contrato, com as variáveis substituídas.
 */
export function TerminalCard({
    icon: Icon,
    tone = 'neutral',
    title,
    children,
    footer,
    className,
}: {
    icon: typeof XCircle;
    tone?: TerminalTone;
    title: string;
    children?: ReactNode;
    footer?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'border-border bg-card shadow-card flex flex-col gap-4 rounded-[14px] border p-5 sm:p-[22px]',
                className,
            )}
        >
            <span
                className={cn(
                    'flex size-[52px] items-center justify-center rounded-[14px]',
                    tone === 'danger'
                        ? 'bg-danger-bg text-danger'
                        : 'bg-muted text-text-secondary',
                )}
            >
                <Icon className="size-6" />
            </span>
            <div>
                <h1 className="text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                    {title}
                </h1>
                <div className="text-text-secondary mt-2 text-[13.5px] leading-[1.55]">
                    {children}
                </div>
            </div>
            {footer}
        </div>
    );
}

export const TERMINAL_ICONS = {
    expired: CalendarX2,
    canceled: Ban,
    refused: XCircle,
    invalid: Link2,
} as const;
