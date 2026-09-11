import { CheckCircle2, FileText } from 'lucide-react';
import type { BatchItem } from '@/components/in-person/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateMedium, formatDateTime } from '@/lib/format';

const TONE: Record<
    BatchItem['state'],
    'success' | 'info' | 'warning' | 'neutral' | 'danger' | 'draft'
> = {
    available: 'info',
    done: 'success',
    individual: 'warning',
    waiting: 'neutral',
    expired: 'draft',
    canceled: 'draft',
    refused: 'danger',
    closed: 'draft',
};

/**
 * Lista dos documentos do lote, com o estado de cada um. Cada item tem o seu
 * botão: não existe "autorizar todos". Itens encerrados aparecem com o motivo
 * e sem botão.
 */
export function BatchItemList({
    items,
    onOpen,
    busyId = null,
}: {
    items: BatchItem[];
    onOpen: (id: string) => void;
    busyId?: string | null;
}) {
    return (
        <ul className="flex flex-col gap-2.5">
            {items.map((item) => (
                <li
                    key={item.id}
                    className="border-border bg-card flex flex-col gap-2.5 rounded-[12px] border p-3.5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="bg-primary-soft text-primary grid size-9 shrink-0 place-items-center rounded-[10px]">
                            {item.state === 'done' ? (
                                <CheckCircle2 className="size-5" />
                            ) : (
                                <FileText className="size-5" />
                            )}
                        </span>
                        <div className="min-w-0">
                            <p className="truncate text-[14.5px] font-semibold">
                                {item.title ?? 'Documento'}
                            </p>
                            <p className="text-muted-foreground text-[12.5px]">
                                {item.display_code}
                                {item.expires_at &&
                                    ` · prazo ${formatDateMedium(item.expires_at)}`}
                                {item.authorized_at &&
                                    ` · autorizado em ${formatDateTime(item.authorized_at)}`}
                            </p>
                            {item.reason && (
                                <p className="text-warning mt-1 text-[12.5px]">
                                    {item.reason}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="flex shrink-0 items-center gap-2">
                        <Badge variant={TONE[item.state]}>
                            {item.state_label}
                        </Badge>
                        {item.authorizable && (
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => onOpen(item.id)}
                                disabled={busyId !== null}
                            >
                                {busyId === item.id && (
                                    <Spinner className="size-4" />
                                )}
                                {item.open ? 'Continuar' : 'Abrir e revisar'}
                            </Button>
                        )}
                    </div>
                </li>
            ))}
        </ul>
    );
}
