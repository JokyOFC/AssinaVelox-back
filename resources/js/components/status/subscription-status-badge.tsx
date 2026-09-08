import { Badge } from '@/components/ui/badge';
import {
    subscriptionStatusLabels,
    subscriptionStatusTones,
} from '@/lib/labels';
import type { SubscriptionStatus } from '@/types/enums';

/** Badge de status da assinatura/conta (DESIGN §5.4 / §5.5). */
export function SubscriptionStatusBadge({
    status,
    label,
    cancelAtPeriodEnd = false,
    solid = false,
    className,
}: {
    status: SubscriptionStatus;
    label?: string | null;
    cancelAtPeriodEnd?: boolean;
    /** Pill sólida verde usada no card navy "Plano atual". */
    solid?: boolean;
    className?: string;
}) {
    if (solid && status === 'active') {
        return (
            <Badge variant="solidSuccess" className={className}>
                {cancelAtPeriodEnd ? 'Cancela ao fim do ciclo' : 'Ativo'}
            </Badge>
        );
    }

    if (status === 'active' && cancelAtPeriodEnd) {
        return (
            <Badge variant="warning" dot className={className}>
                {label ?? 'Cancela ao fim do ciclo'}
            </Badge>
        );
    }

    return (
        <Badge
            variant={subscriptionStatusTones[status]}
            dot
            className={className}
        >
            {label ?? subscriptionStatusLabels[status]}
        </Badge>
    );
}
