import { Badge } from '@/components/ui/badge';
import { envelopeStatusPresentation } from '@/lib/labels';
import { cn } from '@/lib/utils';
import type { EnvelopeStatus } from '@/types/enums';

/**
 * Badge de status do documento (DESIGN §5.1 / ROUTES §6.1).
 * Prefere `label` vindo do backend (`status_label`); calcula a variante
 * localmente pela regra Aguardando × Em andamento.
 */
export function EnvelopeStatusBadge({
    status,
    signedCount = 0,
    label,
    className,
}: {
    status: EnvelopeStatus;
    signedCount?: number;
    label?: string | null;
    className?: string;
}) {
    const presentation = envelopeStatusPresentation(status, signedCount, label);

    return (
        <Badge variant={presentation.tone} dot className={cn(className)}>
            {presentation.label}
        </Badge>
    );
}
