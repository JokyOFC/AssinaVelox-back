import { AvatarInitials } from '@/components/avatar-initials';
import { cn } from '@/lib/utils';

/**
 * Prévia do carimbo visual (FieldType `stamp`): logo e nome da organização
 * dentro de uma moldura na cor de destaque — o mesmo desenho que o servidor
 * grava no PDF (App\Services\Branding\Stamp\StampRenderer).
 *
 * Contrato para o editor de campos e para a página pública (fora da área
 * C-BRAND): renderizar dentro da caixa do campo `stamp`, com `fill`.
 * É representação visual, não prova — a legenda diz isso quando `caption`.
 */
export function StampPreview({
    displayName,
    logoUrl,
    initials,
    primary,
    accent,
    caption = false,
    fill = false,
    className,
}: {
    displayName: string;
    logoUrl: string | null;
    initials?: string;
    primary: string;
    accent: string;
    caption?: boolean;
    /** Ocupa 100% da caixa do campo (editor / página pública). */
    fill?: boolean;
    className?: string;
}) {
    return (
        <figure className={cn('grid gap-1.5', fill && 'size-full', className)}>
            <div
                className={cn(
                    'flex items-center gap-3 rounded-sm border-2 bg-white/70 px-3',
                    fill ? 'size-full' : 'aspect-[3/1] w-full max-w-[300px]',
                )}
                style={{ borderColor: accent }}
            >
                {logoUrl ? (
                    <img
                        src={logoUrl}
                        alt=""
                        className="h-[70%] max-w-[40%] shrink-0 object-contain"
                    />
                ) : initials ? (
                    <AvatarInitials
                        initials={initials}
                        tone="organization"
                        size="md"
                    />
                ) : null}
                <span
                    className="line-clamp-2 min-w-0 text-[13px] leading-tight font-bold"
                    style={{ color: primary }}
                >
                    {displayName}
                </span>
            </div>
            {caption && (
                <figcaption className="text-muted-foreground text-[11.5px] leading-[1.5]">
                    Carimbo visual da organização — representação visual, não
                    prova.
                </figcaption>
            )}
        </figure>
    );
}
