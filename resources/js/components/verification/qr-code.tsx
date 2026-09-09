import { useMemo } from 'react';
import { cn } from '@/lib/utils';
import { qrMatrix, qrSvgPath } from './qr';

/**
 * QR da URL de verificação. Desenhado no cliente, em SVG, sem requisição a
 * nenhum serviço externo (a página pública não carrega rastreadores nem
 * imagens de terceiros).
 */
export function QrCode({
    value,
    size = 132,
    title = 'QR da página de verificação',
    className,
}: {
    value: string;
    /** Lado em pixels. */
    size?: number;
    title?: string;
    className?: string;
}) {
    const matrix = useMemo(() => qrMatrix(value), [value]);

    if (!matrix) {
        return null;
    }

    const quiet = 4;
    const extent = matrix.size + quiet * 2;

    return (
        <svg
            role="img"
            aria-label={title}
            viewBox={`0 0 ${extent} ${extent}`}
            width={size}
            height={size}
            shapeRendering="crispEdges"
            className={cn(
                'border-border rounded-lg border bg-white',
                className,
            )}
        >
            <title>{title}</title>
            <rect width={extent} height={extent} fill="#ffffff" />
            <path d={qrSvgPath(matrix, quiet)} fill="#0b1f42" />
        </svg>
    );
}
