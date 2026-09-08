import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Estado vazio (DESIGN §4.16).
 * - `variant="inline"`: texto discreto dentro de tabelas ("Nenhum documento…").
 * - `variant="card"`: ícone 52px em tile azul-suave, título 20px, texto e CTA.
 */
export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    variant = 'card',
    className,
}: {
    icon?: LucideIcon;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    variant?: 'card' | 'inline';
    className?: string;
}) {
    if (variant === 'inline') {
        return (
            <div
                className={cn(
                    'px-5 py-12 text-center text-[13.5px] text-muted-foreground',
                    className,
                )}
            >
                <p className="font-medium text-text-secondary">{title}</p>
                {description && <p className="mt-1">{description}</p>}
                {action && (
                    <div className="mt-4 flex justify-center">{action}</div>
                )}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'flex flex-col items-center px-6 py-12 text-center',
                className,
            )}
        >
            {Icon && (
                <span className="mb-4 flex size-[52px] items-center justify-center rounded-[14px] bg-primary-soft text-primary">
                    <Icon className="size-6" />
                </span>
            )}
            <h3 className="text-[20px] leading-[1.25] font-bold">{title}</h3>
            {description && (
                <p className="mt-2 max-w-[440px] text-[13.5px] leading-[1.55] text-text-secondary">
                    {description}
                </p>
            )}
            {action && <div className="mt-5 flex gap-2">{action}</div>}
        </div>
    );
}
