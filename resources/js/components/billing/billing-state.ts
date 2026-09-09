import { formatDateMedium, usagePercent } from '@/lib/format';
import type { Plan, Subscription } from '@/types';
import type { SubscriptionStatus } from '@/types/enums';

/**
 * Plano como as telas de cobrança o consomem. `Plan` (types/models.ts) é o
 * contrato de ROUTES §2.15/§2.16; os campos abaixo são acréscimos opcionais
 * que o backend pode enviar. Enquanto não vierem, a interface simplesmente
 * não afirma nada sobre eles — em especial, **não** inventa que um preço é
 * real quando o flag de sandbox está ausente.
 */
export interface BillingPlan extends Plan {
    /** `plans.code` (mesmo valor de `key`; ambos são enviados pelo backend). */
    code?: string;
    description?: string | null;
    /** `plans.is_sandbox` — plano de desenvolvimento, preço fictício. */
    is_sandbox?: boolean;
    /** Alternativa explícita: o preço é um placeholder, não uma oferta. */
    price_is_placeholder?: boolean;
    /** `plans.is_public` — falso = catálogo interno, ainda não anunciado. */
    is_public?: boolean;
}

/** Preço fictício: nunca pode ser apresentado como oferta real. */
export function isSandboxPlan(plan: BillingPlan | null | undefined): boolean {
    return plan?.is_sandbox === true || plan?.price_is_placeholder === true;
}

export type BillingInterval = 'monthly' | 'yearly';

/**
 * Estado da assinatura para a interface. `canceling` não existe no enum do
 * backend: é `active` + `cancel_at_period_end`, que ROUTES §6.5 manda mostrar
 * como "Cancela em {data}".
 */
export type SubscriptionState = SubscriptionStatus | 'canceling';

export interface SubscriptionSummary {
    state: SubscriptionState;
    /** Rótulo do badge. */
    badge: string;
    /** Linha de contexto do ciclo (renovação, vencimento, fim do trial). */
    line: string | null;
    /** `true` quando o envio de documentos está bloqueado pela cobrança. */
    blocksSending: boolean;
}

/**
 * Traduz assinatura + `cancel_at_period_end` em um estado único de interface.
 * Regras de RECONCILIACAO §4 Q20: `past_due` bloqueia o envio (não a leitura
 * nem o download); 15 dias depois a assinatura é cancelada e a organização
 * volta ao plano Grátis.
 */
export function describeSubscription(
    subscription: Subscription,
): SubscriptionSummary {
    const end = subscription.current_period_end;
    const endLabel = end ? formatDateMedium(end) : null;
    const fallback = subscription.status_label;

    if (subscription.status === 'active' && subscription.cancel_at_period_end) {
        return {
            state: 'canceling',
            badge: endLabel ? `Cancela em ${endLabel}` : 'Renovação cancelada',
            line: endLabel
                ? `A renovação está cancelada. O plano continua ativo até ${endLabel} e depois a organização volta ao plano Grátis.`
                : 'A renovação está cancelada. Ao fim do ciclo a organização volta ao plano Grátis.',
            blocksSending: false,
        };
    }

    switch (subscription.status) {
        case 'active':
            return {
                state: 'active',
                badge: fallback || 'Ativo',
                line: endLabel ? `Renova em ${endLabel}.` : null,
                blocksSending: false,
            };
        case 'trialing': {
            const trial = subscription.trial_ends_at ?? end;

            return {
                state: 'trialing',
                badge: fallback || 'Em trial',
                line: trial
                    ? `Período de avaliação até ${formatDateMedium(trial)}.`
                    : 'Período de avaliação em andamento.',
                blocksSending: false,
            };
        }
        case 'past_due':
            return {
                state: 'past_due',
                badge: fallback || 'Inadimplente',
                line: endLabel
                    ? `O ciclo venceu em ${endLabel} e o pagamento ainda não foi confirmado.`
                    : 'O pagamento do ciclo ainda não foi confirmado.',
                blocksSending: true,
            };
        case 'pending':
            return {
                state: 'pending',
                badge: fallback || 'Pendente',
                line: 'Aguardando a confirmação do primeiro pagamento pelo Mercado Pago.',
                blocksSending: true,
            };
        case 'canceled':
            return {
                state: 'canceled',
                badge: fallback || 'Cancelado',
                line: endLabel ? `Encerrado em ${endLabel}.` : null,
                blocksSending: false,
            };
        case 'expired':
            return {
                state: 'expired',
                badge: fallback || 'Expirado',
                line: endLabel
                    ? `Expirou em ${endLabel}; a organização voltou ao plano Grátis.`
                    : 'A organização voltou ao plano Grátis.',
                blocksSending: true,
            };
    }
}

export type UsageLevel = 'unlimited' | 'ok' | 'warning' | 'exhausted';

/** Nível de uso de uma cota: destaque a partir de 90 %, bloqueio em 100 %. */
export function usageLevel(
    used: number,
    limit: number | null | undefined,
): UsageLevel {
    if (limit === null || limit === undefined || limit <= 0) {
        return 'unlimited';
    }

    if (used >= limit) {
        return 'exhausted';
    }

    return (usagePercent(used, limit) ?? 0) >= 90 ? 'warning' : 'ok';
}

/** Quantos envelopes ainda cabem no ciclo (null = ilimitado). */
export function remainingQuota(
    used: number,
    limit: number | null | undefined,
): number | null {
    if (limit === null || limit === undefined || limit <= 0) {
        return null;
    }

    return Math.max(0, limit - used);
}

/** Retorno do Checkout Pro (`billing.return`). */
export type CheckoutOutcome = 'success' | 'pending' | 'failure';

function isOutcome(value: string | null): value is CheckoutOutcome {
    return value === 'success' || value === 'pending' || value === 'failure';
}

/**
 * Resultado do retorno do checkout. A prop do servidor tem precedência; sem
 * ela, lê `?checkout=` da URL (o Checkout Pro devolve o usuário por
 * `billing.return`, que redireciona para cá). Nunca deduz "aprovado" de
 * qualquer outra pista: a confirmação é sempre do provedor.
 */
export function checkoutOutcome(
    fromProps?: CheckoutOutcome | null,
): CheckoutOutcome | null {
    if (fromProps) {
        return fromProps;
    }

    if (typeof window === 'undefined') {
        return null;
    }

    const value = new URLSearchParams(window.location.search).get('checkout');

    return isOutcome(value) ? value : null;
}

/** "R$ 49/mês" ou "R$ 490/ano" a partir do intervalo escolhido. */
export function priceForInterval(
    plan: Pick<BillingPlan, 'price_cents_monthly' | 'price_cents_yearly'>,
    interval: BillingInterval,
): { cents: number; suffix: '/mês' | '/ano'; fallbackToMonthly: boolean } {
    if (interval === 'yearly' && plan.price_cents_yearly !== null) {
        return {
            cents: plan.price_cents_yearly,
            suffix: '/ano',
            fallbackToMonthly: false,
        };
    }

    return {
        cents: plan.price_cents_monthly,
        suffix: '/mês',
        fallbackToMonthly: interval === 'yearly',
    };
}
