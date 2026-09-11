import { BellRing } from 'lucide-react';
import { reminderSummary } from '@/components/envelopes/phase2-routes';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { plural } from '@/lib/format';
import type { EnvelopeReminders, ReminderSettings } from '@/types/models';

type NumericKey = 'first_after_days' | 'interval_days' | 'max_count';

const FALLBACK_LIMITS: EnvelopeReminders['limits'] = {
    first_after_days: { min: 1, max: 30 },
    interval_days: { min: 1, max: 30 },
    max_count: { min: 1, max: 10 },
};

function range(min: number, max: number): number[] {
    const safeMin = Math.max(1, Math.trunc(min));
    const safeMax = Math.max(safeMin, Math.trunc(max));

    return Array.from({ length: safeMax - safeMin + 1 }, (_, i) => safeMin + i);
}

/**
 * Lembretes automáticos do envelope (Fase 2 §2.5; mock "Nova solicitação", passo 1).
 *
 * Só é renderizado com `reminders.available`; sem a flag o passo 1 mantém o switch
 * desabilitado com o selo "Fase 2". Grava por `PUT documentos/{envelope}/lembretes`
 * (autosave do wizard). A janela de horário e o fuso são da organização e aparecem só
 * como informação — mudam em Configurações › Padrões de assinatura.
 */
export function RemindersControl({
    reminders,
    value,
    onChange,
    errors,
    disabled,
}: {
    reminders: EnvelopeReminders;
    value: ReminderSettings;
    onChange: (next: ReminderSettings) => void;
    errors: Record<string, string>;
    disabled?: boolean;
}) {
    const limits = reminders.limits ?? FALLBACK_LIMITS;

    const set = (key: NumericKey, raw: string) =>
        onChange({ ...value, [key]: Number(raw) });

    const numeric: { key: NumericKey; label: string; unit: string }[] = [
        {
            key: 'first_after_days',
            label: 'Primeiro lembrete após',
            unit: 'dia',
        },
        { key: 'interval_days', label: 'Repetir a cada', unit: 'dia' },
        { key: 'max_count', label: 'Máximo de lembretes', unit: 'lembrete' },
    ];

    return (
        <div className="border-border flex flex-col gap-3 rounded-[10px] border p-3.5">
            <label className="flex items-center justify-between gap-3">
                <span className="min-w-0">
                    <span className="flex flex-wrap items-center gap-2 text-[13.5px] font-semibold">
                        <BellRing className="text-primary size-3.5" />
                        Lembretes automáticos
                        {reminders.is_default && (
                            <Badge variant="phase">Padrão da organização</Badge>
                        )}
                    </span>
                    <span className="text-muted-foreground block text-[12.5px]">
                        {value.enabled
                            ? `${reminderSummary(value)} para quem ainda não assinou`
                            : 'Desativados — reenvie convites manualmente pelo detalhe do documento.'}
                    </span>
                </span>
                <Switch
                    checked={value.enabled}
                    disabled={disabled}
                    aria-label="Lembretes automáticos"
                    onCheckedChange={(enabled) =>
                        onChange({ ...value, enabled: enabled === true })
                    }
                />
            </label>

            {value.enabled && (
                <>
                    <div
                        className="grid gap-3"
                        style={{
                            gridTemplateColumns:
                                'repeat(auto-fit, minmax(150px, 1fr))',
                        }}
                    >
                        {numeric.map(({ key, label, unit }) => (
                            <div key={key} className="grid gap-1.5">
                                <Label
                                    htmlFor={`reminders-${key}`}
                                    className="text-[12.5px]"
                                >
                                    {label}
                                </Label>
                                <Select
                                    value={String(value[key])}
                                    disabled={disabled}
                                    onValueChange={(raw) => set(key, raw)}
                                >
                                    <SelectTrigger
                                        id={`reminders-${key}`}
                                        size="sm"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {range(
                                            limits[key].min,
                                            limits[key].max,
                                        ).map((option) => (
                                            <SelectItem
                                                key={option}
                                                value={String(option)}
                                            >
                                                {plural(option, unit)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors[key]} />
                            </div>
                        ))}
                    </div>
                    <p className="text-muted-foreground text-[12px] leading-[1.5]">
                        Enviados entre {reminders.window.window_start_hour}h e{' '}
                        {reminders.window.window_end_hour}h,{' '}
                        {reminders.timezone_label}, só para quem está na vez e
                        ainda não assinou nem aprovou. Visualizadores não
                        recebem lembretes. Cada lembrete traz um link novo, e o
                        anterior deixa de valer.
                    </p>
                </>
            )}

            <InputError message={errors.enabled} />
        </div>
    );
}
