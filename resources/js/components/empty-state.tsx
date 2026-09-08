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
                    'text-muted-foreground px-5 py-12 text-center text-[13.5px]',
                    className,
                )}
            >
                <p className="text-text-secondary font-medium">{title}</p>
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
                <span className="bg-primary-soft text-primary mb-4 flex size-[52px] items-center justify-center rounded-[14px]">
                    <Icon className="size-6" />
                </span>
            )}
            <h3 className="text-[20px] leading-[1.25] font-bold">{title}</h3>
            {description && (
                <p className="text-text-secondary mt-2 max-w-[440px] text-[13.5px] leading-[1.55]">
                    {description}
                </p>
            )}
            {action && <div className="mt-5 flex gap-2">{action}</div>}
        </div>
    );
}
