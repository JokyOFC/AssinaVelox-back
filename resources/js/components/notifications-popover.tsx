import { router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { formatRelativeDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as notificationsIndex, read as markRead } from '@/routes/notifications';
import type { AppNotification, Paginated } from '@/types';

/**
 * Sino com popover (ROUTES §5.3): carrega `notifications.index` (JSON) ao
 * abrir; "Marcar todas como lidas" → POST `notifications.read`.
 * Ponto vermelho quando `counts.unread_notifications > 0`.
 */
export function NotificationsPopover({ className }: { className?: string }) {
    const { counts } = usePage().props;
    const unread = counts?.unread_notifications ?? 0;
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<AppNotification[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [marking, setMarking] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;
        setLoading(true);

        fetch(notificationsIndex.url(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = (await response.json()) as
                    | Paginated<AppNotification>
                    | AppNotification[];

                if (!cancelled) {
                    setItems(Array.isArray(data) ? data : data.data);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setItems([]);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [open]);

    const handleMarkAll = () => {
        setMarking(true);
        router.post(
            markRead.url(),
            { ids: [] },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    setMarking(false);
                    setItems((prev) =>
                        prev
                            ? prev.map((n) => ({
                                  ...n,
                                  read_at: n.read_at ?? new Date().toISOString(),
                              }))
                            : prev,
                    );
                },
            },
        );
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-label={
                        unread > 0
                            ? `Notificações (${unread} não lidas)`
                            : 'Notificações'
                    }
                    className={cn(
                        'relative flex size-[34px] items-center justify-center rounded-lg text-text-secondary transition-colors hover:bg-accent hover:text-foreground',
                        className,
                    )}
                >
                    <Bell className="size-[17px]" />
                    {unread > 0 && (
                        <span className="absolute top-[7px] right-2 size-[7px] rounded-full border-2 border-background bg-danger-solid" />
                    )}
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                sideOffset={8}
                className="w-[360px] rounded-[10px] p-0 shadow-popover"
            >
                <div className="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                    <span className="text-[13.5px] font-semibold">Notificações</span>
                    <Button
                        variant="link"
                        size="xxs"
                        disabled={marking || !items?.some((n) => !n.read_at)}
                        onClick={handleMarkAll}
                        className="text-[12.5px]"
                    >
                        {marking ? <Spinner className="size-3.5" /> : <CheckCheck className="size-3.5" />}
                        Marcar todas como lidas
                    </Button>
                </div>
                <div className="max-h-[380px] overflow-y-auto">
                    {loading && (
                        <div className="flex items-center justify-center gap-2 py-8 text-[13px] text-muted-foreground">
                            <Spinner className="size-4" /> Carregando…
                        </div>
                    )}
                    {!loading && items && items.length === 0 && (
                        <p className="px-4 py-8 text-center text-[13px] text-muted-foreground">
                            Você não tem notificações.
                        </p>
                    )}
                    {!loading &&
                        items?.map((notification) => (
                            <button
                                key={notification.id}
                                type="button"
                                onClick={() => {
                                    setOpen(false);

                                    if (notification.url) {
                                        router.visit(notification.url);
                                    }
                                }}
                                className={cn(
                                    'flex w-full items-start gap-3 border-b border-muted px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-row-hover',
                                )}
                            >
                                <span
                                    className={cn(
                                        'mt-[7px] size-1.5 shrink-0 rounded-full',
                                        notification.read_at
                                            ? 'bg-border'
                                            : 'bg-primary',
                                    )}
                                />
                                <span className="min-w-0 flex-1">
                                    <span
                                        className={cn(
                                            'block text-[13.5px] leading-[1.35]',
                                            notification.read_at
                                                ? 'font-medium text-text-secondary'
                                                : 'font-semibold text-foreground',
                                        )}
                                    >
                                        {notification.title}
                                    </span>
                                    {notification.body && (
                                        <span className="mt-0.5 block text-[12.5px] leading-[1.45] text-muted-foreground">
                                            {notification.body}
                                        </span>
                                    )}
                                    <span className="mt-1 block text-[11.5px] text-muted-foreground tabular">
                                        {formatRelativeDateTime(notification.created_at)}
                                    </span>
                                </span>
                            </button>
                        ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}
