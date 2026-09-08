import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * Logo horizontal oficial (public/images/logo.png, 842×297).
 * `inverted` aplica `brightness(0) invert(1)` para uso sobre navy (DESIGN §7).
 */
export default function AppLogo({
    className,
    inverted = false,
    height = 30,
    ...props
}: Omit<ComponentProps<'img'>, 'src' | 'alt' | 'height'> & {
    inverted?: boolean;
    height?: number;
}) {
    return (
        <img
            src="/images/logo.png"
            alt="AssinaVelox"
            style={{
                height,
                filter: inverted ? 'brightness(0) invert(1)' : undefined,
            }}
            className={cn('block w-auto', className)}
            {...props}
        />
    );
}

/** Marca compacta "A + check" (mesma arte do favicon), para espaços pequenos. */
export function AppMark({ className, ...props }: ComponentProps<'svg'>) {
    return (
        <svg
            viewBox="0 0 64 64"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden
            className={cn('size-8', className)}
            {...props}
        >
            <rect width="64" height="64" rx="14" fill="#0b1f42" />
            <path
                d="M18 48 L30 16 L36 16 L48 48 L41 48 L37.5 38 L28.5 38 L25 48 Z M30.5 32 L35.5 32 L33 24.5 Z"
                fill="#ffffff"
            />
            <path
                d="M40 27 L46 33 L58 19"
                fill="none"
                stroke="#1257c9"
                strokeWidth="5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}
