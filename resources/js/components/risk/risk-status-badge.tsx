import { Badge } from '@/components/ui/badge';

export type RiskStatusValue = 'normal' | 'watch' | 'restricted';

export type RiskReviewStatusValue =
    | 'open'
    | 'watching'
    | 'cleared'
    | 'confirmed';

const RISK_STATUS: Record<
    RiskStatusValue,
    { label: string; variant: 'success' | 'warning' | 'danger' }
> = {
    normal: { label: 'Normal', variant: 'success' },
    watch: { label: 'Em observação', variant: 'warning' },
    restricted: { label: 'Envio suspenso', variant: 'danger' },
};

const REVIEW_STATUS: Record<
    RiskReviewStatusValue,
    { label: string; variant: 'info' | 'warning' | 'success' | 'danger' }
> = {
    open: { label: 'Aguardando revisão', variant: 'info' },
    watching: { label: 'Mantido em observação', variant: 'warning' },
    cleared: { label: 'Liberado', variant: 'success' },
    confirmed: { label: 'Restrição confirmada', variant: 'danger' },
};

/** Estado de risco da organização (normal | watch | restricted). */
export function RiskStatusBadge({ status }: { status: string }) {
    const config = RISK_STATUS[status as RiskStatusValue] ?? RISK_STATUS.normal;

    return (
        <Badge variant={config.variant} dot>
            {config.label}
        </Badge>
    );
}

/** Situação de um caso da fila de revisão. */
export function RiskReviewStatusBadge({ status }: { status: string }) {
    const config =
        REVIEW_STATUS[status as RiskReviewStatusValue] ?? REVIEW_STATUS.open;

    return (
        <Badge variant={config.variant} dot>
            {config.label}
        </Badge>
    );
}
