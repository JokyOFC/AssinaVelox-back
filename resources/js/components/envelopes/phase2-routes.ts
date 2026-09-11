import { plural } from '@/lib/format';
import { schedule as envelopeSchedule } from '@/routes/envelopes';
import { update as remindersUpdate } from '@/routes/envelopes/reminders';
import type { ReminderSettings } from '@/types/models';

/*
 * Rotas de lembretes e agendamento (Fase 2 §2.5 — docs/fase-2/lembretes-e-agendamento.md
 * §7.1), agora pelos helpers do Wayfinder (`envelopes.schedule`, `envelopes.schedule.cancel`
 * — mesma URL, método DELETE — e `envelopes.reminders.update`).
 *
 * Com a flag `reminders` desligada, nada aqui é chamado: a interface só aparece quando a
 * prop `reminders.available` chega `true` — e aí as rotas respondem.
 */

/** `POST`/`DELETE documentos/{envelope}/agendamento`. */
export function scheduleUrl(envelopeId: string): string {
    return envelopeSchedule(envelopeId).url;
}

/** `PUT documentos/{envelope}/lembretes`. */
export function remindersUrl(envelopeId: string): string {
    return remindersUpdate(envelopeId).url;
}

/**
 * Resumo da cadência no mesmo formato de `ReminderSettings::summary()` ("A cada 2 dias ·
 * até 3 lembretes"). Calculado no cliente para não mostrar o valor antigo enquanto o
 * autosave ainda não voltou.
 */
export function reminderSummary(settings: ReminderSettings): string {
    if (!settings.enabled) {
        return 'Desativados';
    }

    return `A cada ${plural(settings.interval_days, 'dia')} · até ${plural(settings.max_count, 'lembrete')}`;
}

/**
 * `Y-m-d\TH:i` de um instante no fuso informado — o formato do `<input
 * type="datetime-local">` e o que `POST agendamento` interpreta no fuso da organização.
 * Fuso inválido cai no fuso do navegador (o servidor revalida de qualquer forma).
 */
export function zonedInputValue(instant: Date, timeZone: string): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    try {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).formatToParts(instant);
        const get = (type: string) =>
            parts.find((part) => part.type === type)?.value ?? '00';

        return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
    } catch {
        return `${instant.getFullYear()}-${pad(instant.getMonth() + 1)}-${pad(instant.getDate())}T${pad(instant.getHours())}:${pad(instant.getMinutes())}`;
    }
}

/** "2026-09-15T09:00" → "15/09/2026 às 09:00" (sem conversão de fuso). */
export function formatInputValue(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value);

    return match
        ? `${match[3]}/${match[2]}/${match[1]} às ${match[4]}:${match[5]}`
        : value;
}
