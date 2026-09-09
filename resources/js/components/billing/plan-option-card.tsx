import { Check, FlaskConical } from 'lucide-react';
import {
    type BillingInterval,
    type BillingPlan,
    isSandboxPlan,
    priceForInterval,
} from '@/components/billing/billing-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatCurrencyCompact, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

export type PlanOption = BillingPlan & {
    limits: {
        envelopes_per_month: number | null;
        members: number | null;
        storage_bytes: number | null;
    };
    highlighted: boolean;
    cta: 'current' | 'upgrade' | 'downgrade' | 'contact';
};

const CTA_LABEL: Record<PlanOption['cta'], string> = {
    current: 'Plano atual',
    upgrade: 'Contratar',
    downgrade: 'Mudar para este plano',
    contact: 'Sob consulta',
};

/** Rótulos de cota que o backend repete dentro de `features`. */
function isLimitLabel(feature: string): boolean {
    return (
        /^[\d.]+\s+documentos(\/|\s)/i.test(feature) ||
        /^documentos ilimitados$/i.test(feature) ||
        /^[\d.]+\s+usuári/i.test(feature) ||
        /^usuários ilimitados$/i.test(feature)
    );
}

/** Limites do plano em linguagem de produto, sempre a partir dos dados. */
function limitLines(plan: PlanOption): string[] {
    const lines: string[] = [];

    lines.push(
        plan.limits.envelopes_per_month === null
            ? 'Documentos ilimitados por mês'
            : `${formatNumber(plan.limits.envelopes_per_month)} documentos por mês`,
    );

    lines.push(
        plan.limits.members === null
            ? 'Usuários ilimitados'
            : `${formatNumber(plan.limits.members)} ${plan.limits.members === 1 ? 'usuário' : 'usuários'}`,
    );

    if (plan.limits.storage_bytes !== null) {
        lines.push(`${formatBytes(plan.limits.storage_bytes)} de arquivos`);
    }

    return lines;
}

/**
 * Card de um plano na comparação (ROUTES §2.16). Marca o plano atual, mostra
 * limites e recursos e leva ao checkout. Plano sandbox recebe o selo e a
 * ressalva do preço fictício em cada card, além do aviso do topo da página.
 */
export function PlanOptionCard({
    plan,
    isCurrent,
    interval,
    redirecting = false,
    disabled = false,
    onChoose,
}: {
    plan: PlanOption;
    isCurrent: boolean;
    interval: BillingInterval;
    redirecting?: boolean;
    disabled?: boolean;
    onChoose: (plan: PlanOption) => void;
}) {
    const sandbox = isSandboxPlan(plan);
    const price = priceForInterval(plan, interval);
    const free = price.cents === 0;
    // `PlanController::featureLabels` já injeta cota e assentos na lista de
    // recursos; aqui eles viram linhas de limite, então são removidos daqui.
    const features = plan.features.filter((feature) => !isLimitLabel(feature));

    return (
        <div
            className={cn(
                'bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5',
                isCurrent
                    ? 'border-primary'
                    : plan.highlighted
                      ? 'border-primary-soft-border'
                      : 'border-border',
            )}
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground text-[11px] font-bold tracking-[.16em] uppercase">
                    {plan.name}
                </span>
                {isCurrent && <Badge variant="info">Plano atual</Badge>}
                {!isCurrent && plan.highlighted && (
                    <Badge variant="planProfessional">Mais popular</Badge>
                )}
                {sandbox && (
                    <Badge variant="warning">
                        <FlaskConical aria-hidden className="size-3" />
                        Sandbox
                    </Badge>
                )}
            </div>

            <div>
                <span className="tabular text-[30px] leading-none font-bold tracking-[-.02em]">
                    {free ? 'Grátis' : formatCurrencyCompact(price.cents)}
                </span>
                {!free && (
                    <span className="text-muted-foreground ml-1 text-[13px]">
                        {price.suffix}
                    </span>
                )}
                {price.fallbackToMonthly && !free && (
                    <p className="text-muted-foreground mt-1 text-[12px]">
                        Este plano só tem cobrança mensal.
                    </p>
                )}
                {sandbox && !free && (
                    <p className="text-warning mt-1 text-[12px] font-semibold">
                        Valor fictício de desenvolvimento — não é oferta.
                    </p>
                )}
            </div>

            <ul className="text-text-secondary flex flex-col gap-2 text-[13px]">
                {limitLines(plan).map((line) => (
                    <li key={line} className="flex gap-2">
                        <Check
                            aria-hidden
                            className="text-success mt-0.5 size-3.5 shrink-0"
                        />
                        {line}
                    </li>
                ))}
                {features.map((feature) => (
                    <li key={feature} className="flex gap-2">
                        <Check
                            aria-hidden
                            className="text-success mt-0.5 size-3.5 shrink-0"
                        />
                        {feature}
                    </li>
                ))}
            </ul>

            {plan.cta === 'contact' && (
                <p className="text-muted-foreground mt-auto text-[12px] leading-[1.5]">
                    A contratação deste plano é feita com a equipe da
                    AssinaVelox.
                </p>
            )}

            <Button
                className={cn('w-full', plan.cta !== 'contact' && 'mt-auto')}
                variant={plan.cta === 'upgrade' ? 'default' : 'outline'}
                disabled={
                    plan.cta === 'current' ||
                    plan.cta === 'contact' ||
                    disabled ||
                    redirecting
                }
                onClick={() => onChoose(plan)}
            >
                {redirecting && <Spinner className="size-4" />}
                {redirecting
                    ? 'Redirecionando para o Mercado Pago…'
                    : CTA_LABEL[plan.cta]}
            </Button>
        </div>
    );
}
