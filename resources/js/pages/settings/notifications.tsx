import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { notificationChannelLabels } from '@/lib/labels';
import { notifications as settingsNotifications } from '@/routes/settings';
import { update as updateNotifications } from '@/routes/settings/notifications';
import type {
    NotificationChannel,
    NotificationEvent,
    NotificationPreferenceRow,
} from '@/types';

export interface SettingsNotificationsProps {
    events: NotificationPreferenceRow[];
    digest_time_label: string;
}

const CHANNELS: NotificationChannel[] = ['mail', 'database'];

/** Configurações › Notificações (ROUTES §2.14; DESIGN §6.11): matriz eventos × canais. */
export default function SettingsNotifications({
    events,
    digest_time_label,
}: SettingsNotificationsProps) {
    const initial = Object.fromEntries(
        events.map((event) => [
            event.key,
            CHANNELS.filter((channel) => event.channels[channel]),
        ]),
    ) as Record<NotificationEvent, NotificationChannel[]>;

    const form = useForm<{
        preferences: Record<NotificationEvent, NotificationChannel[]>;
    }>({
        preferences: initial,
    });

    const toggle = (
        event: NotificationEvent,
        channel: NotificationChannel,
        checked: boolean,
    ) => {
        const current = form.data.preferences[event] ?? [];
        const next = checked
            ? [...new Set([...current, channel])]
            : current.filter((c) => c !== channel);

        form.setData('preferences', {
            ...form.data.preferences,
            [event]: next,
        });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(updateNotifications.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Configurações · Notificações" />

            <form
                onSubmit={submit}
                className="border-border bg-card shadow-card rounded-xl border"
            >
                <div className="px-5 pt-5 pb-3">
                    <Heading
                        variant="small"
                        title="Notificações"
                        description="Escolha como quer ser avisado sobre cada evento. Notificações no app aparecem no sino."
                    />
                </div>
                <div className="overflow-x-auto">
                    <div style={{ minWidth: 520 }}>
                        <div
                            className="border-muted bg-background text-muted-foreground grid h-10 items-center border-y px-5 text-[12px] font-semibold"
                            style={{
                                gridTemplateColumns:
                                    'minmax(0,2.4fr) repeat(3, 90px)',
                            }}
                        >
                            <span>Evento</span>
                            {CHANNELS.map((channel) => (
                                <span key={channel} className="text-center">
                                    {notificationChannelLabels[channel]}
                                </span>
                            ))}
                            <span className="flex flex-col items-center gap-0.5 text-center leading-none">
                                WhatsApp
                                <Badge variant="phase" className="text-[10px]">
                                    Fase 2
                                </Badge>
                            </span>
                        </div>
                        {events.map((event) => (
                            <div
                                key={event.key}
                                className="border-muted hover:bg-row-hover grid items-center border-b px-5 py-[11px] last:border-b-0"
                                style={{
                                    gridTemplateColumns:
                                        'minmax(0,2.4fr) repeat(3, 90px)',
                                }}
                            >
                                <span className="min-w-0 pr-3">
                                    <span className="block text-[13.5px] font-medium">
                                        {event.label}
                                    </span>
                                    <span className="text-muted-foreground block text-[12px]">
                                        {event.description}
                                    </span>
                                </span>
                                {CHANNELS.map((channel) => {
                                    const locked =
                                        event.locked?.[channel] ?? false;
                                    const checked =
                                        form.data.preferences[
                                            event.key
                                        ]?.includes(channel) ?? false;

                                    return (
                                        <span
                                            key={channel}
                                            className="flex justify-center"
                                        >
                                            <Checkbox
                                                aria-label={`${event.label} por ${notificationChannelLabels[channel]}`}
                                                checked={checked}
                                                disabled={locked}
                                                onCheckedChange={(v) =>
                                                    toggle(
                                                        event.key,
                                                        channel,
                                                        v === true,
                                                    )
                                                }
                                            />
                                        </span>
                                    );
                                })}
                                <span className="flex justify-center">
                                    <Checkbox
                                        disabled
                                        aria-label="WhatsApp (Fase 2)"
                                    />
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-[12.5px]">
                    <span>
                        Resumo diário de pendências enviado às{' '}
                        {digest_time_label}.
                    </span>
                    <Button
                        type="submit"
                        size="sm"
                        disabled={form.processing || !form.isDirty}
                    >
                        {form.processing && <Spinner />}
                        Salvar preferências
                    </Button>
                </div>
            </form>
        </>
    );
}

SettingsNotifications.layout = {
    breadcrumbs: [{ title: 'Configurações', href: settingsNotifications() }],
};
