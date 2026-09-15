import { useForm } from '@inertiajs/react';
import { Rocket, TriangleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber, plural } from '@/lib/format';
import { confirm } from '@/routes/bulk_generations';
import type { BulkCounts, BulkMode, DirectSend } from './types';

export interface ConfirmOptions {
    can_send: boolean;
    can_schedule: boolean;
    direct_send: DirectSend;
    schedule: {
        min_lead_minutes: number;
        max_days: number;
        timezone_label: string;
        default_value: string;
    };
}

export interface QuotaInfo {
    remaining: number | null;
    needed: number;
    fits: boolean;
}

/**
 * Confirmação do lote: o que fazer com cada documento e quanto da cota será
 * reservado. As linhas com erro ficam de fora e não consomem nada.
 */
export function ConfirmPanel({
    batchId,
    counts,
    quota,
    options,
}: {
    batchId: string;
    counts: BulkCounts;
    quota: QuotaInfo;
    options: ConfirmOptions;
}) {
    const form = useForm<{ mode: BulkMode; scheduled_for: string }>({
        mode: 'review',
        scheduled_for: options.schedule.default_value,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const sendBlocked = !options.direct_send.available
        ? options.direct_send.reason
        : !options.can_send
          ? 'Sua função não permite enviar documentos.'
          : null;

    const scheduleBlocked =
        sendBlocked ??
        (!options.can_schedule
            ? 'O envio agendado não está disponível no plano atual.'
            : null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(confirm.url(batchId), { preserveScroll: true });
    };

    const nothingToDo = counts.valid === 0;

    return (
        <form
            onSubmit={submit}
            className="border-border bg-card flex flex-col gap-4 rounded-xl border p-5"
        >
            <div>
                <h2 className="text-[14px] font-semibold">Confirmar e gerar</h2>
                <p className="text-text-secondary text-[12.5px]">
                    {plural(
                        counts.valid,
                        'documento será gerado',
                        'documentos serão gerados',
                    )}
                    {counts.invalid > 0 &&
                        ` · ${plural(counts.invalid, 'linha com erro fica', 'linhas com erro ficam')} de fora`}
                    .
                </p>
            </div>

            <RadioGroup
                value={form.data.mode}
                onValueChange={(value) =>
                    form.setData('mode', value as BulkMode)
                }
                className="gap-2"
            >
                <ModeOption
                    value="review"
                    title="Gerar para revisar antes de enviar"
                    description="Cada documento fica em Documentos para você conferir e enviar."
                />
                <ModeOption
                    value="send"
                    title="Enviar assim que cada documento for gerado"
                    description="Os participantes recebem o convite na hora, pelas regras de sempre."
                    blocked={sendBlocked}
                />
                <ModeOption
                    value="schedule"
                    title="Agendar o envio"
                    description={`Todos saem no mesmo horário (${options.schedule.timezone_label}).`}
                    blocked={scheduleBlocked}
                />
            </RadioGroup>

            {form.data.mode === 'schedule' && !scheduleBlocked && (
                <div className="grid gap-1.5">
                    <Label htmlFor="scheduled_for">Data e hora do envio</Label>
                    <Input
                        id="scheduled_for"
                        type="datetime-local"
                        value={form.data.scheduled_for}
                        onChange={(event) =>
                            form.setData('scheduled_for', event.target.value)
                        }
                        className="max-w-xs"
                    />
                    <p className="text-muted-foreground text-[12px]">
                        Com pelo menos {options.schedule.min_lead_minutes}{' '}
                        minutos de antecedência e até{' '}
                        {options.schedule.max_days} dias à frente.
                    </p>
                    {errors.scheduled_for && (
                        <p className="text-danger text-[12.5px]">
                            {errors.scheduled_for}
                        </p>
                    )}
                </div>
            )}

            <div
                className={
                    quota.fits
                        ? 'border-border bg-muted/30 rounded-lg border p-3 text-[12.5px]'
                        : 'border-warning-border bg-warning-bg text-warning flex gap-2 rounded-lg border p-3 text-[12.5px]'
                }
            >
                {!quota.fits && (
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                )}
                <span>
                    {quota.remaining === null
                        ? `Serão reservados ${formatNumber(quota.needed)} documentos da cota. Seu plano não tem limite de documentos.`
                        : quota.fits
                          ? `Serão reservados ${formatNumber(quota.needed)} dos ${formatNumber(quota.remaining)} documentos disponíveis neste ciclo.`
                          : `A cota comporta mais ${formatNumber(quota.remaining)} documentos neste ciclo e o lote tem ${formatNumber(quota.needed)} linhas válidas. Remova linhas da planilha ou amplie o plano.`}
                </span>
            </div>

            {(errors.batch || errors.mode) && (
                <p className="text-danger text-[12.5px]" role="alert">
                    {errors.batch ?? errors.mode}
                </p>
            )}

            <Button
                type="submit"
                disabled={form.processing || nothingToDo || !quota.fits}
                className="self-start"
            >
                {form.processing ? <Spinner /> : <Rocket className="size-4" />}
                Confirmar e gerar {formatNumber(counts.valid)}{' '}
                {counts.valid === 1 ? 'documento' : 'documentos'}
            </Button>
        </form>
    );
}

function ModeOption({
    value,
    title,
    description,
    blocked = null,
}: {
    value: BulkMode;
    title: string;
    description: string;
    blocked?: string | null;
}) {
    const id = `bulk-mode-${value}`;

    return (
        <label
            htmlFor={id}
            className="border-border has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-primary-soft/30 flex cursor-pointer gap-3 rounded-lg border p-3 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60"
        >
            <RadioGroupItem
                id={id}
                value={value}
                disabled={blocked !== null}
                className="mt-0.5"
            />
            <span className="flex flex-col">
                <span className="text-[13.5px] font-medium">{title}</span>
                <span className="text-text-secondary text-[12.5px]">
                    {blocked ?? description}
                </span>
            </span>
        </label>
    );
}
