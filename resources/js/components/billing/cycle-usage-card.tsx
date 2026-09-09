import { remainingQuota, usageLevel } from '@/components/billing/billing-state';
import Heading from '@/components/heading';
import { ProgressMeter } from '@/components/progress-meter';
import {
    formatBytes,
    formatDateMedium,
    formatDayMonth,
    formatNumber,
} from '@/lib/format';
import type { PlanUsage } from '@/types';

/**
 * "Uso no ciclo" (DESIGN §6.11): documentos enviados sobre a cota do plano,
 * usuários e armazenamento. O medidor fica âmbar a partir de 90 % (§4.22) e a
 * nota abaixo diz o que acontece ao esgotar — reservar cota é o que trava o
 * envio (ledger `plan_consumptions`), não o download nem a leitura.
 */
export function CycleUsageCard({
    usage,
    periodStart,
    periodEnd,
}: {
    usage: PlanUsage;
    periodStart: string | null;
    periodEnd: string | null;
}) {
    const envelopes = usage.envelopes;
    const level = usageLevel(envelopes.used, envelopes.limit);
    const left = remainingQuota(envelopes.used, envelopes.limit);

    const hint =
        level === 'exhausted'
            ? 'Cota do ciclo esgotada — novos envios ficam bloqueados até a renovação ou a troca de plano.'
            : level === 'warning'
              ? `Restam ${formatNumber(left)} ${left === 1 ? 'documento' : 'documentos'} neste ciclo.`
              : undefined;

    return (
        <div className="border-border bg-card shadow-card flex flex-col gap-3.5 rounded-xl border p-5">
            <Heading
                variant="small"
                title="Uso no ciclo"
                description={
                    periodStart && periodEnd
                        ? `${formatDayMonth(periodStart)} → ${formatDayMonth(periodEnd)}`
                        : 'Ciclo atual'
                }
            />

            <ProgressMeter
                label="Documentos enviados"
                used={envelopes.used}
                limit={envelopes.limit}
                usedLabel={formatNumber(envelopes.used)}
                limitLabel={
                    envelopes.limit !== null
                        ? formatNumber(envelopes.limit)
                        : undefined
                }
                hint={hint}
            />
            <ProgressMeter
                label="Usuários"
                used={usage.members.used}
                limit={usage.members.limit}
                usedLabel={formatNumber(usage.members.used)}
                limitLabel={
                    usage.members.limit !== null
                        ? formatNumber(usage.members.limit)
                        : undefined
                }
            />
            <ProgressMeter
                label="Armazenamento"
                used={usage.storage.used_bytes}
                limit={usage.storage.limit_bytes}
                usedLabel={formatBytes(usage.storage.used_bytes)}
                limitLabel={
                    usage.storage.limit_bytes !== null
                        ? formatBytes(usage.storage.limit_bytes)
                        : undefined
                }
            />

            {periodEnd && (
                <p className="text-muted-foreground text-[12px]">
                    A contagem de documentos zera na renovação, em{' '}
                    {formatDateMedium(periodEnd)}.
                </p>
            )}
        </div>
    );
}
