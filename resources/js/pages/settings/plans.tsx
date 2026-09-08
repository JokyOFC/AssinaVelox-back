import { Head, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { SegmentedControl } from '@/components/segmented-control';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatCurrencyCompact, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { cancel as cancelSubscription, checkout as billingCheckout, index as billingIndex } from '@/routes/billing';
import { index as plansIndex } from '@/routes/plans';
import type { Plan, PlanCode } from '@/types';

export interface PlansProps {
    current_plan: PlanCode;
    interval: 'monthly' | 'yearly';
    plans: (Plan & {
        limits: { envelopes_per_month: number | null; members: number | null; storage_bytes: number | null };
        highlighted: boolean;
        cta: 'current' | 'upgrade' | 'downgrade' | 'contact';
    })[];
}

/** Escolha de plano (ROUTES §2.16) — versão inicial; Wave B refina. */
export default function Plans({ current_plan, interval: initialInterval, plans }: PlansProps) {
    const [interval, setInterval] = useState<'monthly' | 'yearly'>(initialInterval);
    const [redirecting, setRedirecting] = useState<PlanCode | null>(null);

    const choose = (plan: PlansProps['plans'][number]) => {
        if (plan.cta === 'current' || plan.cta === 'contact') {
            return;
        }

        setRedirecting(plan.key);

        if (plan.key === 'free') {
            router.post(cancelSubscription.url(), {}, { onFinish: () => setRedirecting(null) });

            return;
        }

        router.post(billingCheckout.url(), { plan: plan.key, interval }, { onFinish: () => setRedirecting(null) });
    };

    return (
        <>
            <Head title="Planos" />
            <PageHeader
                title="Planos"
                subtitle="Escolha o plano que acompanha o volume de documentos da sua organização. Pagamento via Mercado Pago."
                actions={
                    <SegmentedControl
                        value={interval}
                        onChange={setInterval}
                        options={[
                            { value: 'monthly', label: 'Mensal' },
                            { value: 'yearly', label: 'Anual' },
                        ]}
                    />
                }
            />
            <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))' }}>
                {plans.map((plan) => {
                    const price = interval === 'yearly' && plan.price_cents_yearly !== null ? plan.price_cents_yearly : plan.price_cents_monthly;
                    const isCurrent = plan.key === current_plan;

                    return (
                        <div
                            key={plan.key}
                            className={cn(
                                'flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card',
                                plan.highlighted ? 'border-primary' : 'border-border',
                            )}
                        >
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-[11px] font-bold tracking-[.16em] text-muted-foreground uppercase">{plan.name}</span>
                                {isCurrent && <Badge variant="info">Plano atual</Badge>}
                                {!isCurrent && plan.highlighted && <Badge variant="planProfessional">Mais popular</Badge>}
                            </div>
                            <div>
                                <span className="text-[30px] leading-none font-bold tracking-[-.02em] tabular">
                                    {price === 0 ? 'Grátis' : formatCurrencyCompact(price)}
                                </span>
                                {price > 0 && (
                                    <span className="ml-1 text-[13px] text-muted-foreground">/{interval === 'yearly' ? 'ano' : 'mês'}</span>
                                )}
                            </div>
                            <ul className="flex flex-col gap-2 text-[13px] text-text-secondary">
                                <li className="flex gap-2">
                                    <Check className="mt-0.5 size-3.5 shrink-0 text-success" />
                                    {plan.limits.envelopes_per_month === null ? 'Documentos ilimitados' : `${formatNumber(plan.limits.envelopes_per_month)} documentos/mês`}
                                </li>
                                <li className="flex gap-2">
                                    <Check className="mt-0.5 size-3.5 shrink-0 text-success" />
                                    {plan.limits.members === null ? 'Usuários ilimitados' : `${formatNumber(plan.limits.members)} ${plan.limits.members === 1 ? 'usuário' : 'usuários'}`}
                                </li>
                                {plan.limits.storage_bytes !== null && (
                                    <li className="flex gap-2">
                                        <Check className="mt-0.5 size-3.5 shrink-0 text-success" />
                                        {formatBytes(plan.limits.storage_bytes)} de armazenamento
                                    </li>
                                )}
                                {plan.features.map((feature) => (
                                    <li key={feature} className="flex gap-2">
                                        <Check className="mt-0.5 size-3.5 shrink-0 text-success" />
                                        {feature}
                                    </li>
                                ))}
                            </ul>
                            <Button
                                className="mt-auto w-full"
                                variant={plan.cta === 'upgrade' ? 'default' : 'outline'}
                                disabled={plan.cta === 'current' || plan.cta === 'contact' || redirecting !== null}
                                onClick={() => choose(plan)}
                            >
                                {redirecting === plan.key && <Spinner />}
                                {redirecting === plan.key
                                    ? 'Redirecionando para o Mercado Pago…'
                                    : plan.cta === 'current'
                                      ? 'Plano atual'
                                      : plan.cta === 'upgrade'
                                        ? 'Contratar'
                                        : plan.cta === 'downgrade'
                                          ? 'Mudar para este plano'
                                          : 'Fale com a gente'}
                            </Button>
                        </div>
                    );
                })}
            </div>
        </>
    );
}

Plans.layout = {
    breadcrumbs: [
        { title: 'Plano e cobrança', href: billingIndex() },
        { title: 'Planos', href: plansIndex() },
    ],
};
