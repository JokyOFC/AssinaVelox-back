import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SelectableChip } from '@/components/filter-bar';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { signingOrderLabels } from '@/lib/labels';
import { signing as settingsSigning } from '@/routes/settings';
import { update as updateSigning } from '@/routes/settings/signing';
import type { SigningOrder } from '@/types';

type Range = { min: number; max: number };

/** Padrão de lembretes da organização (Fase 2 §2.5). Horas no fuso da organização. */
export interface ReminderDefaults {
    enabled: boolean;
    first_after_days: number;
    interval_days: number;
    max_count: number;
    window_start_hour: number;
    window_end_hour: number;
    timezone: string;
}

export interface SettingsSigningProps {
    defaults: {
        expires_in_days: number;
        signing_order: SigningOrder;
        initials_on_all_pages: boolean;
        allow_drawn_signature: true;
        allow_typed_signature: boolean;
        allow_uploaded_signature: boolean;
    };
    reminders: ReminderDefaults;
    limits: {
        expires_in_days: Range;
        reminders: {
            first_after_days: Range;
            interval_days: Range;
            max_count: Range;
        };
    };
    phase2: {
        /** `features.reminders` ligada para a organização. */
        reminders: boolean;
        auth_methods: ['email_otp'];
        channels: ['email'];
    };
}

const EXPIRATION_OPTIONS = [3, 7, 15, 30, 45, 60, 90];
const DAY_OPTIONS = [1, 2, 3, 5, 7];

function numbers(min: number, max: number): number[] {
    return Array.from({ length: max - min + 1 }, (_, index) => min + index);
}

/** Opções fixas dentro do limite, sem perder o valor salvo se ele for outro. */
function dayOptions(range: Range, current: number): number[] {
    const options = DAY_OPTIONS.filter(
        (days) => days >= range.min && days <= range.max,
    );

    return options.includes(current)
        ? options
        : [...options, current].sort((a, b) => a - b);
}

function daysLabel(days: number): string {
    return days === 1 ? '1 dia' : `${days} dias`;
}

function hourLabel(hour: number): string {
    return `${String(hour).padStart(2, '0')}:00`;
}

function SwitchRow({
    title,
    description,
    checked,
    onCheckedChange,
    disabled,
    phase2,
}: {
    title: string;
    description: string;
    checked: boolean;
    onCheckedChange?: (checked: boolean) => void;
    disabled?: boolean;
    phase2?: boolean;
}) {
    return (
        <div className="border-muted flex items-center justify-between gap-4 border-t py-3 first:border-t-0">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2 text-[13.5px] font-semibold">
                    {title}
                    {phase2 && <Badge variant="phase">Não ativado</Badge>}
                </div>
                <div className="text-muted-foreground mt-0.5 text-[12.5px]">
                    {description}
                </div>
            </div>
            <Switch
                checked={checked}
                onCheckedChange={onCheckedChange}
                disabled={disabled}
                className="data-[state=unchecked]:bg-border-dashed h-[22px] w-10 [&>span]:size-[18px] [&>span]:data-[state=checked]:translate-x-[18px]"
            />
        </div>
    );
}

function NumberSelect({
    id,
    label,
    value,
    options,
    format,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: number;
    options: number[];
    format: (value: number) => string;
    onChange: (value: number) => void;
    error?: string;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={String(value)}
                onValueChange={(v) => onChange(Number(v))}
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option} value={String(option)}>
                            {format(option)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}

/** Configurações › Padrões de assinatura (ROUTES §2.13; DESIGN §6.11). */
export default function SettingsSigning({
    defaults,
    reminders,
    limits,
    phase2,
}: SettingsSigningProps) {
    const remindersAvailable = phase2.reminders;

    const form = useForm({
        expires_in_days: defaults.expires_in_days,
        signing_order: defaults.signing_order,
        initials_on_all_pages: defaults.initials_on_all_pages,
        allow_typed_signature: defaults.allow_typed_signature,
        allow_uploaded_signature: defaults.allow_uploaded_signature,
        reminders: {
            enabled: reminders.enabled,
            first_after_days: reminders.first_after_days,
            interval_days: reminders.interval_days,
            max_count: reminders.max_count,
            window_start_hour: reminders.window_start_hour,
            window_end_hour: reminders.window_end_hour,
        },
    });

    const errors = form.errors as Record<string, string | undefined>;

    const setReminder = (patch: Partial<typeof form.data.reminders>) =>
        form.setData('reminders', { ...form.data.reminders, ...patch });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // Sem a flag, o payload é o mesmo da Fase 1: os lembretes não vão ao servidor.
        form.transform((data) => {
            if (remindersAvailable) {
                return data;
            }

            const { reminders: omitted, ...rest } = data;
            void omitted;

            return rest;
        });

        form.patch(updateSigning.url(), { preserveScroll: true });
    };

    const intervalOptions = dayOptions(
        limits.reminders.interval_days,
        form.data.reminders.interval_days,
    );

    return (
        <>
            <Head title="Configurações · Padrões de assinatura" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="Padrões de novas solicitações"
                        description="Podem ser alterados em cada envio."
                    />
                    <div
                        className="grid gap-3"
                        style={{
                            gridTemplateColumns:
                                'repeat(auto-fit, minmax(200px, 1fr))',
                        }}
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor="expires_in_days">
                                Prazo para assinatura
                            </Label>
                            <Select
                                value={String(form.data.expires_in_days)}
                                onValueChange={(v) =>
                                    form.setData('expires_in_days', Number(v))
                                }
                            >
                                <SelectTrigger
                                    id="expires_in_days"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {EXPIRATION_OPTIONS.map((days) => (
                                        <SelectItem
                                            key={days}
                                            value={String(days)}
                                        >
                                            {days} dias
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.expires_in_days} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="signing_order">
                                Ordem de assinatura
                            </Label>
                            <Select
                                value={form.data.signing_order}
                                onValueChange={(v) =>
                                    form.setData(
                                        'signing_order',
                                        v as SigningOrder,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="signing_order"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(
                                        Object.keys(
                                            signingOrderLabels,
                                        ) as SigningOrder[]
                                    ).map((key) => (
                                        <SelectItem key={key} value={key}>
                                            {signingOrderLabels[key]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.signing_order} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor="reminders_cadence"
                                className="flex items-center gap-2"
                            >
                                Lembretes automáticos{' '}
                                {!remindersAvailable && (
                                    <Badge variant="phase">Não ativado</Badge>
                                )}
                            </Label>
                            {remindersAvailable ? (
                                <Select
                                    value={
                                        form.data.reminders.enabled
                                            ? String(
                                                  form.data.reminders
                                                      .interval_days,
                                              )
                                            : 'off'
                                    }
                                    onValueChange={(v) =>
                                        v === 'off'
                                            ? setReminder({ enabled: false })
                                            : setReminder({
                                                  enabled: true,
                                                  interval_days: Number(v),
                                              })
                                    }
                                >
                                    <SelectTrigger
                                        id="reminders_cadence"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="off">
                                            Desativados
                                        </SelectItem>
                                        {intervalOptions.map((days) => (
                                            <SelectItem
                                                key={days}
                                                value={String(days)}
                                            >
                                                A cada {daysLabel(days)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <Select value="off" disabled>
                                    <SelectTrigger
                                        id="reminders_cadence"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="off">
                                            Desativados
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            )}
                            <InputError
                                message={
                                    errors['reminders.interval_days'] ??
                                    errors['reminders.enabled']
                                }
                            />
                        </div>
                    </div>

                    {remindersAvailable && form.data.reminders.enabled && (
                        <div className="border-border-dashed flex flex-col gap-3 rounded-lg border border-dashed p-4">
                            <div
                                className="grid gap-3"
                                style={{
                                    gridTemplateColumns:
                                        'repeat(auto-fit, minmax(160px, 1fr))',
                                }}
                            >
                                <NumberSelect
                                    id="reminders_first_after_days"
                                    label="Primeiro lembrete após"
                                    value={form.data.reminders.first_after_days}
                                    options={dayOptions(
                                        limits.reminders.first_after_days,
                                        form.data.reminders.first_after_days,
                                    )}
                                    format={daysLabel}
                                    onChange={(v) =>
                                        setReminder({ first_after_days: v })
                                    }
                                    error={errors['reminders.first_after_days']}
                                />
                                <NumberSelect
                                    id="reminders_max_count"
                                    label="Máximo de lembretes"
                                    value={form.data.reminders.max_count}
                                    options={numbers(
                                        limits.reminders.max_count.min,
                                        limits.reminders.max_count.max,
                                    )}
                                    format={(n) =>
                                        n === 1
                                            ? '1 lembrete'
                                            : `${n} lembretes`
                                    }
                                    onChange={(v) =>
                                        setReminder({ max_count: v })
                                    }
                                    error={errors['reminders.max_count']}
                                />
                                <NumberSelect
                                    id="reminders_window_start_hour"
                                    label="Enviar a partir de"
                                    value={
                                        form.data.reminders.window_start_hour
                                    }
                                    options={numbers(0, 23)}
                                    format={hourLabel}
                                    onChange={(v) =>
                                        setReminder({ window_start_hour: v })
                                    }
                                    error={
                                        errors['reminders.window_start_hour']
                                    }
                                />
                                <NumberSelect
                                    id="reminders_window_end_hour"
                                    label="Enviar até"
                                    value={form.data.reminders.window_end_hour}
                                    options={numbers(1, 24)}
                                    format={hourLabel}
                                    onChange={(v) =>
                                        setReminder({ window_end_hour: v })
                                    }
                                    error={errors['reminders.window_end_hour']}
                                />
                            </div>
                            <span className="text-muted-foreground text-[12px] leading-[1.5]">
                                Horário no fuso da organização (
                                {reminders.timezone}). Os lembretes vão só para
                                quem ainda não assinou e está na vez, e param
                                quando o documento é concluído, recusado, expira
                                ou é cancelado. Cada lembrete traz um link novo,
                                que substitui o anterior.
                            </span>
                        </div>
                    )}

                    <div className="grid gap-1.5">
                        <span className="text-[13px] font-semibold">
                            Autenticação padrão dos signatários
                        </span>
                        <div className="flex flex-wrap gap-2">
                            <SelectableChip selected disabled>
                                Token e-mail
                            </SelectableChip>
                            <SelectableChip selected={false} disabled>
                                Token SMS · não ativado
                            </SelectableChip>
                            <SelectableChip selected={false} disabled>
                                Token WhatsApp · não ativado
                            </SelectableChip>
                        </div>
                        <span className="text-muted-foreground text-[12px] leading-[1.5]">
                            O código por e-mail é o método padrão. SMS e
                            WhatsApp aumentam a robustez do aceite e dependem de
                            ativação pela plataforma.
                        </span>
                    </div>
                </div>

                <div className="border-border bg-card shadow-card flex flex-col rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="Canais e recursos"
                        className="mb-2"
                    />
                    <SwitchRow
                        title="Envio por e-mail"
                        description="Convites e reenvios por e-mail"
                        checked
                        disabled
                    />
                    <SwitchRow
                        title="Envio por WhatsApp"
                        description="Requer celular do signatário"
                        checked={false}
                        disabled
                        phase2
                    />
                    <SwitchRow
                        title="Envio por SMS"
                        description="Alternativa ao WhatsApp"
                        checked={false}
                        disabled
                        phase2
                    />
                    <SwitchRow
                        title="Permitir assinatura desenhada"
                        description="O signatário desenha a assinatura no celular ou computador (sempre permitido)"
                        checked
                        disabled
                    />
                    <SwitchRow
                        title="Permitir assinatura digitada"
                        description="O signatário digita o nome com uma fonte manuscrita"
                        checked={form.data.allow_typed_signature}
                        onCheckedChange={(v) =>
                            form.setData('allow_typed_signature', v)
                        }
                    />
                    <SwitchRow
                        title="Permitir imagem de assinatura"
                        description="O signatário envia uma imagem PNG/JPG da assinatura"
                        checked={form.data.allow_uploaded_signature}
                        onCheckedChange={(v) =>
                            form.setData('allow_uploaded_signature', v)
                        }
                    />
                    <SwitchRow
                        title="Rubrica automática em todas as páginas"
                        description="Adiciona um campo de rubrica por página ao criar solicitações"
                        checked={form.data.initials_on_all_pages}
                        onCheckedChange={(v) =>
                            form.setData('initials_on_all_pages', v)
                        }
                    />
                </div>

                <div className="flex justify-end">
                    <Button
                        type="submit"
                        disabled={form.processing || !form.isDirty}
                    >
                        {form.processing && <Spinner />}
                        Salvar alterações
                    </Button>
                </div>
            </form>
        </>
    );
}

SettingsSigning.layout = {
    breadcrumbs: [{ title: 'Configurações', href: settingsSigning() }],
};
