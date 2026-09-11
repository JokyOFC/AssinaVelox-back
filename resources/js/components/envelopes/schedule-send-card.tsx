import { router } from '@inertiajs/react';
import { CalendarClock, X } from 'lucide-react';
import { useState } from 'react';
import {
    formatInputValue,
    scheduleUrl,
    zonedInputValue,
} from '@/components/envelopes/phase2-routes';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { EnvelopeReminders } from '@/types/models';

/**
 * Envio agendado (Fase 2 §2.5; docs/fase-2/lembretes-e-agendamento.md §5 e §7).
 *
 * O envelope agendado continua `ready` até a hora marcada, quando o servidor revalida
 * tudo (pendências e cota) e envia. Qualquer edição no documento, nos participantes ou nos
 * campos cancela o agendamento — a tela diz isso antes de confirmar.
 *
 * `allowCreate = false` (detalhe do documento) mostra só o estado e "Cancelar agendamento".
 */
export function ScheduleSendCard({
    envelopeId,
    reminders,
    canSchedule,
    blockedReason,
    waitingSave = false,
    allowCreate = true,
    errors,
    className,
}: {
    envelopeId: string;
    reminders: EnvelopeReminders;
    /** Envelope pronto (sem pendências) e com cota para enviar. */
    canSchedule: boolean;
    blockedReason?: string | null;
    /** Autosave pendente: agendar agora faria a próxima gravação cancelar o agendamento. */
    waitingSave?: boolean;
    allowCreate?: boolean;
    errors: Record<string, string>;
    className?: string;
}) {
    const scheduled = reminders.scheduled_send;
    const timezone = scheduled?.timezone ?? reminders.timezone;
    // Rótulo PT-BR ("horário de Brasília (GMT-3)"); o identificador IANA só entra nas contas.
    const timezoneLabel = scheduled?.timezone_label ?? reminders.timezone_label;
    const timezoneSentence =
        timezoneLabel.charAt(0).toUpperCase() + timezoneLabel.slice(1);
    const limits = reminders.scheduled_send_limits;

    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(scheduled?.input_value ?? '');
    const [busy, setBusy] = useState(false);

    const now = Date.now();
    const min = zonedInputValue(
        new Date(now + limits.min_lead_minutes * 60_000),
        timezone,
    );
    const max = zonedInputValue(
        new Date(now + limits.max_days * 86_400_000),
        timezone,
    );

    const submit = () => {
        setBusy(true);
        router.post(
            scheduleUrl(envelopeId),
            { scheduled_for: value },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const errorsAfter = (page.props.errors ?? {}) as Record<
                        string,
                        string
                    >;

                    if (!errorsAfter.scheduled_for) {
                        setEditing(false);
                    }
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const cancel = () => {
        setBusy(true);
        router.delete(scheduleUrl(envelopeId), {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setBusy(false),
        });
    };

    if (!scheduled && !allowCreate) {
        return null;
    }

    return (
        <div
            className={cn(
                'border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5',
                className,
            )}
        >
            <div className="flex items-start gap-2.5">
                <span className="bg-primary-soft text-primary flex size-8 shrink-0 items-center justify-center rounded-lg">
                    <CalendarClock className="size-4" />
                </span>
                <div className="min-w-0">
                    <p className="text-[14px] font-semibold">
                        {scheduled
                            ? `Envio agendado para ${scheduled.at_local}`
                            : 'Agendar envio'}
                    </p>
                    <p className="text-muted-foreground mt-0.5 text-[12.5px] leading-[1.5]">
                        {scheduled
                            ? `${timezoneSentence}. No momento marcado as pendências e a cota do plano são conferidas de novo; se algo impedir o envio, você é avisado por e-mail e no sino.`
                            : `Escolha data e hora no ${timezoneLabel}, o fuso da organização. Até lá o documento continua como rascunho pronto.`}
                    </p>
                </div>
            </div>

            {editing && (
                <div className="grid gap-1.5">
                    <Label htmlFor="scheduled-for" className="text-[12.5px]">
                        Data e hora do envio
                    </Label>
                    <Input
                        id="scheduled-for"
                        type="datetime-local"
                        value={value}
                        min={min}
                        max={max}
                        disabled={busy}
                        aria-invalid={Boolean(errors.scheduled_for)}
                        onChange={(event) => setValue(event.target.value)}
                    />
                    <p className="text-muted-foreground text-[12px]">
                        Entre {limits.min_lead_minutes} minutos e{' '}
                        {limits.max_days} dias a partir de agora.
                        {value && ` Envio em ${formatInputValue(value)}.`}
                    </p>
                    <InputError message={errors.scheduled_for} />
                </div>
            )}

            <p className="text-muted-foreground text-[12px] leading-[1.5]">
                Qualquer alteração no documento, nos participantes ou nos campos
                cancela o agendamento. “Enviar agora” continua disponível.
            </p>

            {!canSchedule && !scheduled && blockedReason && (
                <p className="text-warning text-[12.5px]">{blockedReason}</p>
            )}

            <div className="flex flex-wrap gap-2">
                {editing ? (
                    <>
                        <Button
                            size="sm"
                            disabled={
                                busy || !value || !canSchedule || waitingSave
                            }
                            onClick={submit}
                        >
                            {busy && <Spinner className="size-3.5" />}
                            Confirmar agendamento
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() => setEditing(false)}
                        >
                            Voltar
                        </Button>
                    </>
                ) : (
                    <>
                        {allowCreate && (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={busy || !canSchedule}
                                onClick={() => {
                                    setValue(scheduled?.input_value ?? '');
                                    setEditing(true);
                                }}
                            >
                                <CalendarClock className="size-3.5" />
                                {scheduled ? 'Reagendar' : 'Agendar envio'}
                            </Button>
                        )}
                        {scheduled && (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={busy}
                                onClick={cancel}
                                className="hover:bg-danger-bg hover:text-danger"
                            >
                                {busy ? (
                                    <Spinner className="size-3.5" />
                                ) : (
                                    <X className="size-3.5" />
                                )}
                                Cancelar agendamento
                            </Button>
                        )}
                    </>
                )}
            </div>

            {waitingSave && editing && (
                <p className="text-muted-foreground text-[12px]">
                    Aguarde o rascunho terminar de salvar para agendar.
                </p>
            )}
        </div>
    );
}
