import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

/** Cores fechadas de etiqueta (App\Services\Tags\TagColor) → tokens do design. */
export type TagColor = 'blue' | 'green' | 'amber' | 'red' | 'gray' | 'navy';

export interface TagOption {
    id: string;
    name: string;
    /** Um dos valores de `TagColor`; valor desconhecido cai no cinza. */
    color: string;
}

const COLOR_CLASSES: Record<TagColor, string> = {
    blue: 'bg-primary-soft text-primary border-primary-soft-border',
    green: 'bg-success-bg text-success border-success-border',
    amber: 'bg-warning-bg text-warning border-warning-border',
    red: 'bg-danger-bg text-danger border-danger-border',
    gray: 'bg-muted text-text-secondary border-border',
    navy: 'bg-navy text-white border-transparent',
};

export const TAG_COLOR_LABELS: Record<TagColor, string> = {
    blue: 'Azul',
    green: 'Verde',
    amber: 'Âmbar',
    red: 'Vermelho',
    gray: 'Cinza',
    navy: 'Marinho',
};

export function tagColorClasses(color: string): string {
    return COLOR_CLASSES[color as TagColor] ?? COLOR_CLASSES.gray;
}

/** Bolinha de cor (seletor de cor e menus). */
export function TagSwatch({
    color,
    className,
}: {
    color: string;
    className?: string;
}) {
    return (
        <span
            aria-hidden
            className={cn(
                'inline-block size-3 shrink-0 rounded-full border',
                tagColorClasses(color),
                className,
            )}
        />
    );
}

/**
 * Chip de etiqueta (DESIGN §4.7 — chip neutro, 11.5px/600, `rounded-md`),
 * colorido pela paleta fechada. `onRemove` mostra o "x".
 */
export function TagChip({
    tag,
    onRemove,
    size = 'sm',
    className,
}: {
    tag: TagOption;
    onRemove?: () => void;
    size?: 'xs' | 'sm';
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex max-w-[160px] items-center gap-1 rounded-md border font-semibold whitespace-nowrap',
                size === 'xs'
                    ? 'px-1.5 py-px text-[11px]'
                    : 'px-[7px] py-0.5 text-[11.5px]',
                tagColorClasses(tag.color),
                className,
            )}
        >
            <span className="truncate">{tag.name}</span>
            {onRemove && (
                <button
                    type="button"
                    onClick={(event) => {
                        event.stopPropagation();
                        onRemove();
                    }}
                    aria-label={`Remover etiqueta ${tag.name}`}
                    className="-mr-0.5 rounded-sm opacity-70 hover:opacity-100"
                >
                    <X className="size-3" />
                </button>
            )}
        </span>
    );
}

/** Lista compacta de chips com "+N" quando passa do limite. */
export function TagChipList({
    tags,
    max = 3,
    onRemove,
}: {
    tags: TagOption[];
    max?: number;
    onRemove?: (tag: TagOption) => void;
}) {
    if (tags.length === 0) {
        return null;
    }

    const visible = tags.slice(0, max);
    const rest = tags.length - visible.length;

    return (
        <span className="flex min-w-0 flex-wrap items-center gap-1">
            {visible.map((tag) => (
                <TagChip
                    key={tag.id}
                    tag={tag}
                    size="xs"
                    onRemove={onRemove ? () => onRemove(tag) : undefined}
                />
            ))}
            {rest > 0 && (
                <span
                    className="text-muted-foreground text-[11px] font-semibold"
                    title={tags
                        .slice(max)
                        .map((tag) => tag.name)
                        .join(', ')}
                >
                    +{rest}
                </span>
            )}
        </span>
    );
}
