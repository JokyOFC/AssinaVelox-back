import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Variantes conforme DESIGN_SYSTEM §4.1.
 * - default: primário azul (#1257c9 → hover #0f4bb0)
 * - outline: branco com borda #d5dce9, hover #f4f8fe
 * - ghost: transparente, hover #eef2f9 (ícones e ações discretas)
 * - destructive: outline vermelho (não existe botão vermelho sólido nos mocks)
 * - dashed: "Adicionar…" tracejado
 * - link: texto azul sem sublinhado
 * - success: outline verde ("Reenviar link")
 * - onNavy / ghostOnNavy: botões sobre superfícies navy
 */
const buttonVariants = cva(
    "inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 rounded-lg text-[13.5px] font-semibold whitespace-nowrap transition-[color,background-color,border-color,box-shadow] outline-none focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-primary/18 disabled:pointer-events-none disabled:opacity-60 aria-invalid:border-danger [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-[15px]",
    {
        variants: {
            variant: {
                default:
                    'bg-primary text-primary-foreground shadow-primary hover:bg-primary-hover',
                outline:
                    'border border-input bg-white text-foreground shadow-card hover:bg-accent-subtle',
                'outline-sm':
                    'border border-input bg-white text-[12px] text-foreground hover:border-primary hover:bg-accent-subtle hover:text-primary',
                secondary:
                    'bg-secondary text-secondary-foreground hover:bg-accent',
                ghost: 'text-text-secondary hover:bg-accent hover:text-foreground',
                destructive:
                    'border border-danger-border bg-white text-danger hover:bg-danger-bg',
                dashed: 'border border-dashed border-border-dashed bg-transparent text-text-secondary hover:border-primary hover:bg-accent-subtle hover:text-primary',
                success:
                    'border border-success-border bg-white text-success hover:bg-success-bg',
                link: 'h-auto px-0 text-primary hover:text-primary-hover',
                onNavy: 'bg-white text-navy hover:bg-primary-soft',
                ghostOnNavy:
                    'border border-white/20 bg-transparent text-white hover:bg-white/10',
            },
            size: {
                default: 'h-9 px-3.5',
                lg: 'h-10 px-4 text-[14px]',
                xl: 'h-11 rounded-[10px] px-4 text-[14px]',
                sm: 'h-[34px] px-3 text-[13px]',
                xs: 'h-8 px-3 text-[13px]',
                xxs: 'h-7 rounded-md px-2.5 text-[12px]',
                icon: 'size-9',
                'icon-sm': 'size-8',
                'icon-xs': 'size-[30px] rounded-md',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

function Button({
    className,
    variant,
    size,
    asChild = false,
    ...props
}: React.ComponentProps<'button'> &
    VariantProps<typeof buttonVariants> & {
        asChild?: boolean;
    }) {
    const Comp = asChild ? Slot : 'button';

    return (
        <Comp
            data-slot="button"
            className={cn(buttonVariants({ variant, size, className }))}
            {...props}
        />
    );
}

export { Button, buttonVariants };
