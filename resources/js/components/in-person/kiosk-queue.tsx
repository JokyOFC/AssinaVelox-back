import { CheckCircle2, Clock, Lock, UserRound } from 'lucide-react';
import type { KioskQueueItem } from '@/components/in-person/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

const TONE: Record<
    KioskQueueItem['state'],
    'success' | 'info' | 'neutral' | 'draft'
> = {
    available: 'info',
    done: 'success',
    waiting: 'neutral',
    closed: 'draft',
};

/**
 * Fila do dispositivo presencial: quem participa, na ordem, e o estado de
 * cada um. Só nome, papel e estado — nenhum e-mail, telefone, campo ou valor.
 * Quem vai usar o dispositivo toca no próprio nome e confirma o próprio
 * código em seguida.
 */
export function KioskQueue({
    queue,
    onSelect,
    busyId = null,
}: {
    queue: KioskQueueItem[];
    onSelect: (id: string) => void;
    busyId?: string | null;
}) {
    return (
        <ul className="flex flex-col gap-2.5">
            {queue.map((item) => (
                <li
                    key={item.id}
                    className="border-border bg-card flex flex-wrap items-center justify-between gap-3 rounded-[12px] border p-3.5"
                >
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="bg-primary-soft text-primary grid size-10 shrink-0 place-items-center rounded-full">
                            {item.state === 'done' ? (
                                <CheckCircle2 className="size-5" />
                            ) : item.state === 'waiting' ? (
                                <Clock className="size-5" />
                            ) : item.state === 'closed' ? (
                                <Lock className="size-5" />
                            ) : (
                                <UserRound className="size-5" />
                            )}
                        </span>
                        <div className="min-w-0">
                            <p className="truncate text-[15px] font-semibold">
                                {item.name}
                            </p>
                            <p className="text-muted-foreground text-[12.5px]">
                                {item.participant_role_label}
                                {item.role_label &&
                                item.role_label !== item.participant_role_label
                                    ? ` · ${item.role_label}`
                                    : ''}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Badge variant={TONE[item.state]}>
                            {item.accepted_here && item.state === 'done'
                                ? `${item.state_label} nesta sessão`
                                : item.state_label}
                        </Badge>
                        {item.state === 'available' && (
                            <Button
                                type="button"
                                size="lg"
                                onClick={() => onSelect(item.id)}
                                disabled={busyId !== null}
                            >
                                {busyId === item.id && (
                                    <Spinner className="size-4" />
                                )}
                                Sou {item.first_name} — começar
                            </Button>
                        )}
                    </div>
                </li>
            ))}
        </ul>
    );
}
