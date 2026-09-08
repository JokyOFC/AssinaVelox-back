import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type SegmentOption<T extends string> = {
    value: T;
    label: ReactNode;
    count?: number;
    disabled?: boolean;
    title?: string;
};

/**
 * Segmented control (DESIGN §4.5b): trilho `bg-accent rounded-lg p-[3px]`,
 * item ativo branco com `shadow-segment`.
 */
export function SegmentedControl<T extends string>({
    value,
    onChange,
    options,
    size = 'default',
    className,
    ariaLabel,
}: {
    value: T;
    onChange: (value: T) => void;
    options: SegmentOption<T>[];
    size?: 'default' | 'sm';
    className?: string;
    ariaLabel?: string;
}) {
    return (
        <div
            role="tablist"
            aria-label={ariaLabel}
            className={cn(
                'inline-flex max-w-full gap-0.5 overflow-x-auto rounded-lg bg-accent p-[3px]',
                className,
            )}
        >
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        disabled={option.disabled}
                        title={option.title}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md whitespace-nowrap transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                            size === 'sm'
                                ? 'px-2.5 py-1 text-[12px]'
                                : 'px-3 py-[5px] text-[12.5px]',
                            active
                                ? 'bg-white font-semibold text-foreground shadow-segment'
                                : 'font-medium text-text-secondary hover:text-foreground',
                        )}
                    >
                        {option.label}
                        {option.count !== undefined && (
                            <span
                                className={cn(
                                    'rounded-md px-[6px] py-px text-[11px] font-semibold tabular',
                                    active
                                        ? 'bg-primary-soft text-primary'
                                        : 'bg-white/70 text-muted-foreground',
                                )}
                            >
                                {option.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * Tabs underline com contagem (DESIGN §4.5a) — usadas em Documentos,
 * Assinaturas e Admin. Controladas via query-string pelo chamador.
 */
export function UnderlineTabs<T extends string>({
    value,
    onChange,
    options,
    className,
}: {
    value: T;
    onChange: (value: T) => void;
    options: SegmentOption<T>[];
    className?: string;
}) {
    return (
        <div
            role="tablist"
            className={cn(
                'flex gap-0.5 overflow-x-auto border-b border-border px-3 pt-2',
                className,
            )}
        >
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        disabled={option.disabled}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            '-mb-px inline-flex h-[38px] items-center gap-1.5 border-b-2 px-3 text-[13.5px] whitespace-nowrap transition-colors',
                            active
                                ? 'border-primary font-semibold text-primary'
                                : 'border-transparent font-medium text-text-secondary hover:text-foreground',
                        )}
                    >
                        {option.label}
                        {option.count !== undefined && (
                            <span
                                className={cn(
                                    'rounded-md px-[7px] py-px text-[11.5px] font-semibold tabular',
                                    active
                                        ? 'bg-primary-soft text-primary'
                                        : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {option.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

/** Nav vertical de rails (Configurações, pastas, TOC) — DESIGN §4.5c. */
export function RailNavButton({
    active,
    children,
    onClick,
    className,
    trailing,
    disabled,
    asChild,
}: {
    active: boolean;
    children: ReactNode;
    onClick?: () => void;
    className?: string;
    trailing?: ReactNode;
    disabled?: boolean;
    asChild?: boolean;
}) {
    const classes = cn(
        'flex h-9 w-full items-center gap-2 rounded-lg px-3 text-left text-[13.5px] transition-colors',
        active
            ? 'bg-primary-soft font-semibold text-primary'
            : 'font-medium text-text-secondary hover:bg-accent hover:text-foreground',
        disabled && 'pointer-events-none opacity-60',
        className,
    );

    if (asChild) {
        return <div className={classes}>{children}</div>;
    }

    return (
        <button type="button" onClick={onClick} disabled={disabled} className={classes}>
            <span className="min-w-0 flex-1 truncate">{children}</span>
            {trailing}
        </button>
    );
}
