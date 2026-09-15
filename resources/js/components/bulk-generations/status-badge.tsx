import type { ComponentProps } from 'react';
import { Badge } from '@/components/ui/badge';
import type { BulkRow, BulkStatus } from './types';

type Variant = ComponentProps<typeof Badge>['variant'];

const BATCH_VARIANT: Record<BulkStatus, Variant> = {
    draft: 'draft',
    validated: 'info',
    running: 'warning',
    completed: 'success',
    canceled: 'neutral',
};

/** Situação do lote (lista e detalhe). */
export function BulkStatusBadge({
    status,
    label,
}: {
    status: BulkStatus;
    label: string;
}) {
    return <Badge variant={BATCH_VARIANT[status]}>{label}</Badge>;
}

/**
 * Situação de uma linha: gerada mostra o destino do documento (enviado,
 * agendado, pronto…); as demais, a situação da linha.
 */
export function BulkRowBadge({ row }: { row: BulkRow }) {
    if (row.status === 'created' && row.outcome) {
        const variant: Variant =
            row.outcome === 'not_sent'
                ? 'warning'
                : row.outcome === 'draft'
                  ? 'neutral'
                  : row.outcome === 'scheduled'
                    ? 'info'
                    : 'success';

        return <Badge variant={variant}>{row.outcome_label}</Badge>;
    }

    const variant: Variant =
        row.status === 'invalid' || row.status === 'failed'
            ? 'danger'
            : row.status === 'valid' || row.status === 'created'
              ? 'success'
              : row.status === 'canceled'
                ? 'neutral'
                : 'warning';

    return <Badge variant={variant}>{row.status_label}</Badge>;
}
