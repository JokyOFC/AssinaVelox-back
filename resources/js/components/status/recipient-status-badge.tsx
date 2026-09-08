import { Check } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { recipientStatusLabels, recipientStatusTones } from '@/lib/labels';
import type { RecipientStatus } from '@/types/enums';

/** Badge de status do signatário (DESIGN §5.2); "Assinado" leva ícone check. */
export function RecipientStatusBadge({
    status,
    label,
    className,
}: {
    status: RecipientStatus;
    label?: string | null;
    className?: string;
}) {
    const signed = status === 'signed';

    return (
        <Badge
            variant={recipientStatusTones[status]}
            dot={!signed}
            className={className}
        >
            {signed && <Check className="size-3 stroke-[2.5]" />}
            {label ?? recipientStatusLabels[status]}
        </Badge>
    );
}
