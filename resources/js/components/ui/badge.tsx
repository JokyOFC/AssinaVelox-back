import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Badge de status (DESIGN §4.6 / §5): `px-2 py-0.5 rounded-md text-[12px]/600`
 * com borda; tons semânticos com fg/bg/border exatos dos mocks.
 */
const badgeVariants = cva(
    'inline-flex w-fit shrink-0 items-center justify-center gap-1.5 overflow-hidden rounded-md border px-2 py-0.5 text-[12px] font-semibold whitespace-nowrap transition-[color,box-shadow] [&>svg]:pointer-events-none [&>svg]:size-3',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground',
                secondary: 'border-transparent bg-secondary text-secondary-foreground',
                outline: 'text-foreground',
                success: 'border-success-border bg-success-bg text-success',
                warning: 'border-warning-border bg-warning-bg text-warning',
                danger: 'border-danger-border bg-danger-bg text-danger',
                info: 'border-info-border bg-info-bg text-info',
                neutral: 'border-neutral-border bg-neutral-bg text-neutral',
                draft: 'border-neutral-border bg-neutral-bg text-text-secondary',
                /** Badge de plano (sem dot, 11.5px) */
                planEnterprise:
                    'border-transparent bg-navy text-[11.5px] text-white',
                planProfessional:
                    'border-transparent bg-primary-soft text-[11.5px] text-primary',
                planFree:
                    'border-transparent bg-muted text-[11.5px] text-text-secondary',
                /** Pill sólida "Ativo" do card de plano */
                solidSuccess:
                    'border-transparent bg-success-solid text-[11px] font-bold text-white',
                /** Selo "Não ativado" (cinza) */
                phase: 'border-transparent bg-muted px-[7px] py-px text-[11px] font-bold text-muted-foreground',
                /** Contagem âmbar (badge da sidebar "Documentos") */
                count: 'border-transparent bg-warning-bg px-[7px] py-px text-[11px] font-bold text-warning',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

function Badge({
    className,
    variant,
    asChild = false,
    dot = false,
    children,
    ...props
}: React.ComponentProps<'span'> &
    VariantProps<typeof badgeVariants> & { asChild?: boolean; dot?: boolean }) {
    const Comp = asChild ? Slot : 'span';

    return (
        <Comp
            data-slot="badge"
            className={cn(badgeVariants({ variant }), className)}
            {...props}
        >
            {dot && (
                <span
                    aria-hidden
                    className="size-1.5 shrink-0 rounded-full bg-current"
                />
            )}
            {children}
        </Comp>
    );
}

export { Badge, badgeVariants };
