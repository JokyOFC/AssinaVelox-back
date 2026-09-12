<?php

namespace App\Services\Billing;

use App\Enums\AuditEventType;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Efeito de um estorno total ou de uma contestação no plano — Fase 2, onda D, só com a flag
 * `extended_payments`. Roda depois que a CONSULTA ao provedor (GET /v1/payments/{id}) confirmou a
 * transição; nunca a partir do payload de um webhook nem da resposta de um pedido nosso.
 *
 * ## Estorno total (política conservadora — decisão pendente §4.5 item 28, docs §4)
 *
 * Se o pagamento estornado é o que pagou o ciclo VIGENTE, a assinatura:
 * - não renova (`cancel_at_period_end`);
 * - ganha `paid_cycle_refunded_at`, e ao fim do período volta ao Grátis direto, sem passar por
 *   `past_due` (não há dívida; ver `SubscriptionLifecycle::expireRefundedCycles`);
 * - mantém a cota já consumida (não é devolvida) e o acesso até o fim do período.
 * Estorno de um ciclo antigo não mexe no plano. Estorno parcial nunca mexe no plano (o status do
 * pagamento continua `approved`, com `status_detail = partially_refunded`).
 *
 * ## Contestação (`charged_back`)
 *
 * Roadmap §2.20: "`charged_back` suspende envio como `past_due`". Se o pagamento contestado pagou
 * o ciclo vigente, a assinatura ativa vai para `past_due` (bloqueia só o ENVIO). A equipe é
 * alertada em qualquer caso. **Nada** em envelopes, documentos, evidências ou verificação
 * pública é tocado: um documento concluído continua concluído.
 */
final class PaymentReversalEffects
{
    public function __construct(private readonly BillingAlerts $alerts) {}

    public function afterTransition(Payment $payment, PaymentStatus $previous, PaymentStatus $incoming): void
    {
        if ($previous === $incoming) {
            return;
        }

        if ($incoming === PaymentStatus::Refunded) {
            $this->fullRefund($payment);
        }

        if ($incoming === PaymentStatus::ChargedBack) {
            $this->chargeback($payment);
        }
    }

    /**
     * Assinatura cujo ciclo vigente foi pago por este pagamento (ou null).
     */
    public function currentCycleSubscription(Payment $payment): ?Subscription
    {
        if ($payment->activated_at === null || $payment->subscription_id === null) {
            return null;
        }

        $subscription = Subscription::withoutOrganizationScope()
            ->with('plan')
            ->whereKey($payment->subscription_id)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->first();

        if ($subscription === null) {
            return null;
        }

        // Um pagamento ativado depois deste pagou um ciclo posterior: este não é o vigente.
        $newer = Payment::withoutOrganizationScope()
            ->where('subscription_id', $subscription->getKey())
            ->whereKeyNot($payment->getKey())
            ->whereNotNull('activated_at')
            ->where('activated_at', '>', $payment->activated_at)
            ->exists();

        return $newer ? null : $subscription;
    }

    private function fullRefund(Payment $payment): void
    {
        $subscription = $this->currentCycleSubscription($payment);

        if ($subscription === null) {
            Log::info('billing.refund.no_plan_effect', ['payment' => $payment->ulid]);

            return;
        }

        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'canceled_at' => $subscription->canceled_at ?? Carbon::now(),
            'paid_cycle_refunded_at' => Carbon::now(),
        ])->save();

        BillingTrail::record($subscription->organization_id, AuditEventType::SubscriptionCanceled, [
            'plan' => $subscription->plan->code,
            'period_end' => $subscription->current_period_end?->toIso8601String(),
            'reason' => 'payment_refunded',
            'payment' => $payment->ulid,
        ]);

        Log::info('billing.refund.cycle_ends_at_period_end', [
            'payment' => $payment->ulid,
            'subscription' => $subscription->ulid,
            'period_end' => $subscription->current_period_end?->toIso8601String(),
        ]);
    }

    private function chargeback(Payment $payment): void
    {
        $subscription = $this->currentCycleSubscription($payment);
        $suspended = false;

        if ($subscription !== null) {
            $suspended = Subscription::withoutOrganizationScope()
                ->whereKey($subscription->getKey())
                ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
                ->update(['status' => SubscriptionStatus::PastDue->value, 'updated_at' => Carbon::now()]) > 0;

            if ($suspended) {
                BillingTrail::record($subscription->organization_id, AuditEventType::SubscriptionPastDue, [
                    'plan' => $subscription->plan->code,
                    'period_end' => $subscription->current_period_end?->toIso8601String(),
                    'reason' => 'charged_back',
                    'payment' => $payment->ulid,
                ]);
            }
        }

        $this->alerts->raise('billing_chargeback', 'Contestação (chargeback) registrada pelo Mercado Pago.', [
            'payment' => $payment->ulid,
            'organization_id' => $payment->organization_id,
            'amount_cents' => (int) $payment->amount_cents,
            'currency' => $payment->currency,
            'status_detail' => $payment->status_detail,
            'sending_suspended' => $suspended,
        ]);
    }
}
