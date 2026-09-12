<?php

namespace App\Services\Billing;

use App\Enums\AuditEventType;
use App\Enums\PaymentStatus;
use App\Enums\PlanConsumptionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\PlanConsumption;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica um pagamento aprovado à assinatura da organização — **uma única vez por
 * pagamento**.
 *
 * ## O que torna isto idempotente
 *
 * A marca é `payments.activated_at`. Tudo acontece dentro de uma transação que começa
 * relendo o pagamento com `lockForUpdate()`: qualquer segunda chamada (reentrega do
 * webhook, `payment.created` seguido de `payment.updated`, duas filas processando ao
 * mesmo tempo, um `Payment` em memória desatualizado) encontra `activated_at` já
 * preenchido e sai sem tocar em nada. Não há caminho que estenda o período duas vezes
 * pelo mesmo pagamento.
 *
 * A assinatura também é relida sob lock, então duas ativações de pagamentos diferentes
 * na mesma organização se serializam em vez de sobrescrever uma à outra.
 *
 * ## O que a ativação faz
 *
 * - troca o plano da assinatura vigente (ou cria a assinatura, se não houver);
 * - estende `current_period_end`: se o período atual ainda não venceu e o plano é o
 *   mesmo, o novo ciclo começa no fim do atual (o cliente não perde dias por pagar
 *   adiantado); caso contrário começa agora;
 * - **zera o consumo do ciclo**: `envelopes_used` volta a zero e `envelopes_reserved` é
 *   recontado a partir do ledger `plan_consumptions` (reservas ainda em aberto continuam
 *   valendo — zerar às cegas perderia envelopes em trânsito);
 * - limpa `cancel_at_period_end` e `canceled_at` (pagar é retomar);
 * - grava a trilha (`payment.approved` e `subscription.activated`).
 *
 * Nada disso depende do retorno do navegador: só o webhook validado seguido da consulta
 * `GET /v1/payments/{id}` chega até aqui.
 */
class ActivateSubscription
{
    /**
     * @return Subscription|null a assinatura ativada, ou null se nada foi aplicado
     */
    public function handle(Payment $payment): ?Subscription
    {
        return DB::transaction(function () use ($payment): ?Subscription {
            $fresh = Payment::withoutOrganizationScope()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return null;
            }

            if ($fresh->status !== PaymentStatus::Approved) {
                return null;
            }

            if ($fresh->activated_at !== null) {
                // Já aplicado — reentrega, corrida ou instância desatualizada.
                $payment->forceFill(['activated_at' => $fresh->activated_at])->syncOriginal();

                return null;
            }

            $subscription = $this->lockedSubscription($fresh);
            $now = Carbon::now();

            $subscription = $this->applyCycle($subscription, $fresh, $now);

            $fresh->forceFill([
                'subscription_id' => $subscription->getKey(),
                'activated_at' => $now,
            ])->save();

            // Mantém a instância recebida coerente com o banco (o chamador costuma
            // seguir usando-a para notificar/logar).
            $payment->forceFill([
                'subscription_id' => $subscription->getKey(),
                'activated_at' => $now,
            ])->syncOriginal();

            BillingTrail::record($fresh->organization_id, AuditEventType::SubscriptionActivated, [
                'payment' => $fresh->ulid,
                'plan' => $subscription->plan->code,
                'amount_cents' => (int) $fresh->amount_cents,
                'currency' => $fresh->currency,
                'environment' => $fresh->environment->value,
                'period_end' => $subscription->current_period_end?->toIso8601String(),
            ]);

            Log::info('billing.subscription.activated', [
                'organization_id' => $fresh->organization_id,
                'payment' => $fresh->ulid,
                'plan' => $subscription->plan->code,
                'period_end' => $subscription->current_period_end?->toIso8601String(),
            ]);

            return $subscription;
        });
    }

    /**
     * Assinatura vigente da organização, relida sob lock; cria uma se não houver.
     */
    private function lockedSubscription(Payment $payment): Subscription
    {
        $subscription = Subscription::withoutOrganizationScope()
            ->where('organization_id', $payment->organization_id)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::PastDue->value,
                SubscriptionStatus::Pending->value,
                SubscriptionStatus::Expired->value,
                SubscriptionStatus::Canceled->value,
            ])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($subscription !== null) {
            return $subscription;
        }

        $subscription = new Subscription;
        $subscription->forceFill([
            'organization_id' => $payment->organization_id,
            'plan_id' => $payment->plan_id,
            'status' => SubscriptionStatus::Pending,
        ]);
        $subscription->save();

        return $subscription;
    }

    private function applyCycle(Subscription $subscription, Payment $payment, CarbonInterface $now): Subscription
    {
        $samePlan = (int) $subscription->plan_id === (int) $payment->plan_id;
        $currentEnd = $subscription->current_period_end;

        // Pagou adiantado no mesmo plano: emenda no fim do ciclo atual.
        $start = ($samePlan && $currentEnd !== null && $currentEnd->isFuture())
            ? $currentEnd->copy()
            : $now->copy();

        $subscription->forceFill([
            'plan_id' => $payment->plan_id,
            'status' => SubscriptionStatus::Active,
            'started_at' => $subscription->started_at ?? $now,
            'current_period_start' => $start,
            'current_period_end' => $this->periodEnd($start, $payment),
            'canceled_at' => null,
            'cancel_at_period_end' => false,
            // Fase 2, onda D: um novo pagamento aprovado encerra o efeito de um estorno total.
            'paid_cycle_refunded_at' => null,
            'provider' => $payment->provider,
            'envelopes_used' => 0,
            'envelopes_reserved' => $this->openReservations($subscription),
        ])->save();

        return $subscription->refresh()->load('plan');
    }

    /**
     * Fim do novo ciclo, conforme a periodicidade do plano pago.
     */
    private function periodEnd(CarbonInterface $start, Payment $payment): CarbonInterface
    {
        $plan = $payment->plan()->first();
        $period = $plan?->billing_period;

        return $period !== null && $period->value === 'yearly'
            ? $start->copy()->addYearNoOverflow()
            : $start->copy()->addMonthNoOverflow();
    }

    /**
     * Reservas ainda abertas no ledger — o novo ciclo começa devendo apenas o que está
     * de fato em trânsito, nunca o consumo do ciclo anterior.
     */
    private function openReservations(Subscription $subscription): int
    {
        return (int) PlanConsumption::withoutOrganizationScope()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', PlanConsumptionStatus::Reserved->value)
            ->sum('quantity');
    }
}
