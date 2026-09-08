import { Badge } from '@/components/ui/badge';
import {
    paymentDisplayLabels,
    paymentDisplayStatus,
    paymentDisplayTones,
} from '@/lib/labels';
import type { PaymentStatus } from '@/types/enums';

/** Badge de pagamento: agrupa o status bruto do Mercado Pago (DESIGN §5.5). */
export function PaymentStatusBadge({
    status,
    label,
    className,
}: {
    status: PaymentStatus;
    label?: string | null;
    className?: string;
}) {
    const display = paymentDisplayStatus(status);

    return (
        <Badge variant={paymentDisplayTones[display]} dot className={className}>
            {label ?? paymentDisplayLabels[display]}
        </Badge>
    );
}
