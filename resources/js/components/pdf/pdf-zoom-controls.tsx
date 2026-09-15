import { Minus, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n';
import { cn } from '@/lib/utils';

/** Níveis de zoom. `1` = a página ocupa toda a largura disponível. */
export const ZOOM_LEVELS = [0.5, 0.75, 1, 1.25, 1.5, 2] as const;

export const DEFAULT_ZOOM = 1;

export function clampZoom(zoom: number): number {
    const first = ZOOM_LEVELS[0];
    const last = ZOOM_LEVELS[ZOOM_LEVELS.length - 1];

    return Math.min(last, Math.max(first, zoom));
}

function neighbour(zoom: number, direction: 1 | -1): number {
    const levels = [...ZOOM_LEVELS];
    const index = levels.findIndex((level) => level >= zoom - 0.001);
    const current = index === -1 ? levels.length - 1 : index;

    return levels[
        Math.min(levels.length - 1, Math.max(0, current + direction))
    ];
}

/**
 * Controles de zoom do visualizador (DESIGN §4.19): `−` / `100%` / `+`,
 * com o valor centralizado em largura mínima para não "pular" ao mudar.
 */
export function PdfZoomControls({
    zoom,
    onZoomChange,
    disabled,
    className,
}: {
    zoom: number;
    onZoomChange: (zoom: number) => void;
    disabled?: boolean;
    className?: string;
}) {
    const { t } = useI18n();
    const percent = Math.round(zoom * 100);

    return (
        <div className={cn('flex items-center gap-1', className)}>
            <Button
                variant="outline"
                size="icon-sm"
                disabled={disabled || zoom <= ZOOM_LEVELS[0]}
                onClick={() => onZoomChange(neighbour(zoom, -1))}
                aria-label={t('pdf.zoom_out')}
            >
                <Minus className="size-3.5" />
            </Button>
            <button
                type="button"
                disabled={disabled}
                onClick={() => onZoomChange(DEFAULT_ZOOM)}
                title={t('pdf.zoom_fit')}
                className="text-text-secondary hover:text-foreground tabular min-w-10 rounded-md px-1 text-center text-[13px] disabled:opacity-60"
            >
                {percent}%
            </button>
            <Button
                variant="outline"
                size="icon-sm"
                disabled={
                    disabled || zoom >= ZOOM_LEVELS[ZOOM_LEVELS.length - 1]
                }
                onClick={() => onZoomChange(neighbour(zoom, 1))}
                aria-label={t('pdf.zoom_in')}
            >
                <Plus className="size-3.5" />
            </Button>
        </div>
    );
}
