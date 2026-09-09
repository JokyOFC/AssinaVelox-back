import { Link } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';
import {
    type BillingPlan,
    describeSubscription,
    isSandboxPlan,
} from '@/components/billing/billing-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatCurrencyCompact } from '@/lib/format';
import { index as plansIndex } from '@/routes/plans';
import type { Subscription } from '@/types';

/**
 * Card navy "Plano atual" (DESIGN §6.11, aba Plano e cobrança): estado da
 * assinatura, preço, data de renovação e as três ações de plano — trocar,
 * cancelar a renovação e reativar. O cancelamento e a reativação são
 * confirmados pela página (diálogo), este card só dispara.
 */
export function CurrentPlanCard({
    subscription,
    can,
    onCancelRenewal,
    onResumeRenewal,
    resuming = false,
}: {
    subscription: Subscription;
    can: { manage: boolean; cancel: boolean };
    onCancelRenewal: () => void;
    onResumeRenewal: () => void;
    resuming?: boolean;
}) {
    const plan = subscription.plan as BillingPlan;
    const summary = describeSubscription(subscription);
    const sandbox = isSandboxPlan(plan);
    const yearly =
        subscription.interval === 'yearly' && plan.price_cents_yearly !== null;

    const badgeVariant = {
        active: 'solidSuccess',
        canceling: 'warning',
        trialing: 'info',
        past_due: 'danger',
        pending: 'warning',
        canceled: 'neutral',
        expired: 'neutral',
    } as const;

    const price =
        plan.price_cents_monthly === 0 && !yearly
            ? 'Grátis'
            : yearly
              ? `${formatCurrencyCompact(plan.price_cents_yearly)}/ano`
              : `${formatCurrencyCompact(plan.price_cents_monthly)}/mês`;

    return (
        <div className="bg-navy flex flex-col gap-3 rounded-xl p-5 text-white">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-on-navy-muted text-[11px] font-bold tracking-[.16em] uppercase">
                    Plano atual
                </span>
                <Badge variant={badgeVariant[summary.state]}>
                    {summary.badge}
                </Badge>
            </div>

            <div>
                <div className="flex flex-wrap items-baseline gap-2">
                    <span className="text-[26px] leading-none font-extrabold uppercase italic">
                        {plan.name}
                    </span>
                    <span className="text-on-navy-secondary tabular text-[14px]">
                        {price}
                    </span>
                </div>
                {yearly && plan.price_cents_monthly > 0 && (
                    <p className="text-on-navy-muted mt-1 text-[12px]">
                        Equivale a{' '}
                        {formatCurrencyCompact(plan.price_cents_monthly)}
                        /mês.
                    </p>
                )}
                {summary.line && (
                    <p className="text-on-navy-secondary mt-1.5 text-[13px] leading-[1.55]">
                        {summary.line}
                    </p>
                )}
            </div>

            {sandbox && (
                <p className="text-on-navy-secondary flex items-start gap-2 text-[12px] leading-[1.5]">
                    <FlaskConical
                        aria-hidden
                        className="mt-px size-3.5 shrink-0"
                    />
                    Plano de desenvolvimento: o valor acima é fictício e não
                    constitui oferta.
                </p>
            )}

            {plan.features.length > 0 && (
                <p className="text-on-navy-secondary text-[13px] leading-[1.55]">
                    {plan.features.join(' · ')}
                </p>
            )}

            {can.manage && (
                <div className="mt-1 flex flex-wrap gap-2">
                    <Button asChild variant="onNavy" size="sm">
                        <Link href={plansIndex()}>Alterar plano</Link>
                    </Button>

                    {subscription.cancel_at_period_end ? (
                        <Button
                            variant="ghostOnNavy"
                            size="sm"
                            disabled={resuming}
                            onClick={onResumeRenewal}
                        >
                            {resuming && <Spinner className="size-4" />}
                            Reativar renovação
                        </Button>
                    ) : (
                        can.cancel && (
                            <Button
                                variant="ghostOnNavy"
                                size="sm"
                                onClick={onCancelRenewal}
                            >
                                Cancelar renovação
                            </Button>
                        )
                    )}
                </div>
            )}
        </div>
    );
}
