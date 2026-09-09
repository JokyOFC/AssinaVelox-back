import { Check } from 'lucide-react';
import type { CSSProperties, KeyboardEvent, PointerEvent } from 'react';
import type { RecipientColor } from '@/components/envelopes/recipient-colors';
import {
    type NormalizedRect,
    type PageSize,
    RESIZE_HANDLES,
    type ResizeHandle,
    toPixels,
} from '@/lib/geometry';
import { cn } from '@/lib/utils';

export type FieldBoxVariant = 'editor' | 'pending' | 'signed';

const HANDLE_POSITION: Record<ResizeHandle, string> = {
    nw: '-top-[6px] -left-[6px] cursor-nwse-resize',
    ne: '-top-[6px] -right-[6px] cursor-nesw-resize',
    sw: '-bottom-[6px] -left-[6px] cursor-nesw-resize',
    se: '-right-[6px] -bottom-[6px] cursor-nwse-resize',
};

const HANDLE_LABEL: Record<ResizeHandle, string> = {
    nw: 'canto superior esquerdo',
    ne: 'canto superior direito',
    sw: 'canto inferior esquerdo',
    se: 'canto inferior direito',
};

export interface FieldBoxProps {
    rect: NormalizedRect;
    /** Dimensões renderizadas da página, em pixels CSS. */
    page: PageSize;
    color: RecipientColor;
    /** Tag flutuante: "Maria · Assinatura". */
    tag: string;
    /** Conteúdo interno: placeholder, valor preenchido ou nada. */
    hint?: string | null;
    variant?: FieldBoxVariant;
    selected?: boolean;
    /** `false` deixa a caixa apenas visual (detalhe do documento). */
    interactive?: boolean;
    /** Rodapé em 9px — "✓ Assinado em …" / "Aguardando assinatura". */
    footnote?: string | null;
    ariaLabel: string;
    onPointerDownBox?: (event: PointerEvent<HTMLElement>) => void;
    onPointerDownHandle?: (
        handle: ResizeHandle,
        event: PointerEvent<HTMLElement>,
    ) => void;
    onKeyDown?: (event: KeyboardEvent<HTMLElement>) => void;
    onFocus?: () => void;
}

/**
 * Caixa de um campo sobre a página (DESIGN §4.19 "Campos sobrepostos").
 *
 * A posição vem sempre do retângulo normalizado convertido pelas funções puras
 * de `lib/geometry.ts` — o componente não guarda coordenadas próprias.
 */
export function FieldBox({
    rect,
    page,
    color,
    tag,
    hint,
    variant = 'editor',
    selected = false,
    interactive = true,
    footnote,
    ariaLabel,
    onPointerDownBox,
    onPointerDownHandle,
    onKeyDown,
    onFocus,
}: FieldBoxProps) {
    const box = toPixels(rect, page);

    const palette: Record<FieldBoxVariant, CSSProperties> = {
        editor: {
            borderColor: color.solid,
            backgroundColor: color.fill,
            color: color.text,
            borderStyle: 'solid',
        },
        pending: {
            borderColor: 'var(--warning-solid)',
            backgroundColor: 'var(--warning-bg-soft)',
            color: 'var(--warning)',
            borderStyle: 'dashed',
        },
        signed: {
            borderColor: 'var(--success-solid)',
            backgroundColor: 'var(--success-bg)',
            color: 'var(--success)',
            borderStyle: 'solid',
        },
    };

    const tagBackground =
        variant === 'editor'
            ? color.solid
            : variant === 'signed'
              ? 'var(--success-solid)'
              : 'var(--warning-solid)';

    return (
        <div
            role={interactive ? 'button' : 'img'}
            aria-label={ariaLabel}
            aria-pressed={interactive ? selected : undefined}
            tabIndex={interactive ? 0 : -1}
            onPointerDown={interactive ? onPointerDownBox : undefined}
            onKeyDown={interactive ? onKeyDown : undefined}
            onFocus={interactive ? onFocus : undefined}
            style={{
                left: box.left,
                top: box.top,
                width: box.width,
                height: box.height,
                borderWidth: 1.5,
                ...palette[variant],
                ...(selected
                    ? { boxShadow: `0 0 0 3px ${color.solid}33` }
                    : null),
            }}
            className={cn(
                'absolute rounded-md outline-none',
                interactive
                    ? 'cursor-move touch-none focus-visible:ring-2 focus-visible:ring-offset-1'
                    : 'pointer-events-none',
            )}
        >
            <span
                style={{ backgroundColor: tagBackground }}
                className="pointer-events-none absolute -top-[9px] left-2 max-w-[calc(100%-8px)] truncate rounded-[4px] px-1.5 py-px text-[9px] font-bold tracking-[.08em] text-white uppercase"
            >
                {tag}
            </span>

            {hint && (
                <span
                    className={cn(
                        'pointer-events-none absolute inset-x-2 truncate text-[9px]',
                        variant === 'signed'
                            ? 'font-hand bottom-3 -rotate-2 text-[18px] leading-none'
                            : 'top-1/2 -translate-y-1/2',
                    )}
                >
                    {hint}
                </span>
            )}

            {footnote && (
                <span className="pointer-events-none absolute inset-x-2 bottom-0.5 flex items-center gap-0.5 truncate text-[9px]">
                    {variant === 'signed' && (
                        <Check className="size-2 stroke-[3]" />
                    )}
                    {footnote}
                </span>
            )}

            {interactive &&
                selected &&
                RESIZE_HANDLES.map((handle) => (
                    <span
                        key={handle}
                        role="presentation"
                        aria-label={`Redimensionar pelo ${HANDLE_LABEL[handle]}`}
                        onPointerDown={(event) => {
                            event.stopPropagation();
                            onPointerDownHandle?.(handle, event);
                        }}
                        style={{ borderColor: color.solid }}
                        className={cn(
                            'absolute size-3 touch-none rounded-[3px] border-[1.5px] bg-white',
                            HANDLE_POSITION[handle],
                        )}
                    />
                ))}
        </div>
    );
}
