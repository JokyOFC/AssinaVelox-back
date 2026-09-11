import { Lock } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/format';

/**
 * Selo "Preservado" (Fase 2 §2.19): o documento está sob bloqueio de exclusão
 * por preservação — ninguém o exclui (nem a retenção, nem a exclusão manual,
 * nem a exclusão da conta) até a liberação.
 */
export function PreservedBadge({
    since,
    until,
    className,
}: {
    since?: string | null;
    until?: string | null;
    className?: string;
}) {
    const detail = [
        since ? `desde ${formatDate(since)}` : null,
        until ? `até ${formatDate(until)}` : null,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <Badge
            variant="info"
            className={className}
            title={`Preservado${detail ? ` ${detail}` : ''}: não pode ser excluído até a liberação.`}
        >
            <Lock aria-hidden />
            Preservado
        </Badge>
    );
}
