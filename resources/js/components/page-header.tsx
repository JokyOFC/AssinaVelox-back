import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Cabeçalho de página (DESIGN §3.3): eyebrow opcional, h1 24px, subtítulo
 * 13.5px e grupo de ações à direita. `leading` aceita o botão "Voltar" da
 * tela de detalhe.
 */
export function PageHeader({
    eyebrow,
    title,
    subtitle,
    actions,
    leading,
    badge,
    size = 'default',
    className,
}: {
    eyebrow?: string;
    title: ReactNode;
    subtitle?: ReactNode;
    actions?: ReactNode;
    leading?: ReactNode;
    badge?: ReactNode;
    size?: 'default' | 'detail';
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-end justify-between gap-4',
                className,
            )}
        >
            <div className="flex min-w-0 items-start gap-3">
                {leading}
                <div className="min-w-0">
                    {eyebrow && (
                        <p className="text-muted-foreground mb-1.5 text-[11px] font-bold tracking-[.18em] uppercase">
                            {eyebrow}
                        </p>
                    )}
                    <div className="flex min-w-0 flex-wrap items-center gap-2.5">
                        <h1
                            className={cn(
                                'min-w-0 truncate font-bold tracking-[-.01em]',
                                size === 'detail'
                                    ? 'text-[22px] leading-[1.2]'
                                    : 'text-2xl leading-[1.2]',
                            )}
                        >
                            {title}
                        </h1>
                        {badge}
                    </div>
                    {subtitle && (
                        <p className="text-text-secondary mt-1.5 text-[13.5px]">
                            {subtitle}
                        </p>
                    )}
                </div>
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
