import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Título de seção/card (DESIGN §4.10): 15px/600 + descrição 13px.
 * `variant="default"` é o h2 de blocos maiores (18px/700).
 */
export default function Heading({
    title,
    description,
    variant = 'default',
    action,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    variant?: 'default' | 'small';
    action?: ReactNode;
    className?: string;
}) {
    return (
        <header
            className={cn(
                'flex flex-wrap items-start justify-between gap-3',
                className,
            )}
        >
            <div className="min-w-0">
                <h2
                    className={cn(
                        'leading-tight font-semibold',
                        variant === 'small' ? 'text-[15px]' : 'text-[18px] font-bold',
                    )}
                >
                    {title}
                </h2>
                {description && (
                    <p className="mt-1 text-[13px] leading-[1.5] text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {action}
        </header>
    );
}
