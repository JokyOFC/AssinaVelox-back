import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Tecla de atalho (DESIGN §4.26): 11px, borda `border`, fundo `sidebar`. */
export function Kbd({ className, ...props }: ComponentProps<'kbd'>) {
    return (
        <kbd
            className={cn(
                'border-border bg-sidebar text-muted-foreground inline-flex items-center rounded border px-[5px] py-px font-sans text-[11px]',
                className,
            )}
            {...props}
        />
    );
}
