import type { ComponentProps } from 'react';
import type { RecipientStatus } from '@/types/enums';
import { cn } from '@/lib/utils';

/**
 * Avatar quadrado arredondado com iniciais (DESIGN §4.17).
 * Paleta cíclica por índice `i % 4`; `tone` sobrescreve pela semântica
 * (organização navy, usuário logado azul-suave, status do signatário).
 */
export type AvatarTone =
    | 'palette'
    | 'organization'
    | 'user'
    | 'success'
    | 'warning'
    | 'danger'
    | 'neutral';

const PALETTE = [
    'bg-primary-soft text-primary',
    'bg-success-bg text-success',
    'bg-warning-bg text-warning',
    'bg-muted text-text-secondary',
] as const;

const TONES: Record<Exclude<AvatarTone, 'palette'>, string> = {
    organization: 'bg-navy text-white',
    user: 'bg-primary-soft text-primary',
    success: 'bg-success-bg text-success',
    warning: 'bg-warning-bg text-warning',
    danger: 'bg-danger-bg text-danger',
    neutral: 'bg-muted text-text-secondary',
};

const SIZES = {
    xs: 'size-[26px] rounded-[7px] text-[10px]',
    sm: 'size-8 rounded-lg text-[11.5px]',
    md: 'size-[34px] rounded-lg text-[11.5px]',
    lg: 'size-9 rounded-lg text-[12px]',
    xl: 'size-10 rounded-[10px] text-[13px]',
    '2xl': 'size-14 rounded-[10px] text-[18px] font-extrabold',
} as const;

export function recipientTone(status: RecipientStatus): AvatarTone {
    switch (status) {
        case 'signed':
            return 'success';
        case 'refused':
            return 'danger';
        case 'expired':
        case 'canceled':
            return 'neutral';
        default:
            return 'warning';
    }
}

export function AvatarInitials({
    initials,
    index = 0,
    tone = 'palette',
    size = 'lg',
    className,
    ...props
}: ComponentProps<'span'> & {
    initials: string;
    index?: number;
    tone?: AvatarTone;
    size?: keyof typeof SIZES;
}) {
    const color =
        tone === 'palette'
            ? PALETTE[Math.abs(index) % PALETTE.length]
            : TONES[tone];

    return (
        <span
            aria-hidden
            className={cn(
                'inline-flex shrink-0 items-center justify-center font-bold uppercase select-none',
                SIZES[size],
                color,
                className,
            )}
            {...props}
        >
            {initials}
        </span>
    );
}

/** Grupo empilhado (26px, borda branca, -6px) seguido de "1 de 2". */
export function AvatarStack({
    items,
    max = 5,
    progress,
    className,
}: {
    items: { initials: string; status?: RecipientStatus; name?: string }[];
    max?: number;
    progress?: string;
    className?: string;
}) {
    const shown = items.slice(0, max);
    const rest = items.length - shown.length;

    return (
        <div className={cn('flex items-center gap-2.5', className)}>
            <div className="flex pl-1.5">
                {shown.map((item, i) => (
                    <AvatarInitials
                        key={`${item.initials}-${i}`}
                        initials={item.initials}
                        size="xs"
                        tone={item.status ? recipientTone(item.status) : 'user'}
                        title={item.name}
                        className="-ml-1.5 ring-2 ring-white"
                    />
                ))}
                {rest > 0 && (
                    <AvatarInitials
                        initials={`+${rest}`}
                        size="xs"
                        tone="neutral"
                        className="-ml-1.5 ring-2 ring-white"
                    />
                )}
            </div>
            {progress && (
                <span className="text-text-secondary tabular text-[12.5px] whitespace-nowrap">
                    {progress}
                </span>
            )}
        </div>
    );
}
