<?php

namespace App\Services\Billing;

use App\Enums\AuditEventType;
use App\Enums\PlanConsumptionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanConsumption;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ciclo de vida da assinatura: cancelamento ao fim do ciclo, reativação e inadimplência.
 *
 * ## Inadimplência (RECONCILIACAO §4 Q20)
 *
 * O Checkout Pro **não** faz cobrança recorrente (a própria documentação marca
 * "Pagamentos recorrentes" como indisponível para esse produto), então cada ciclo é um
 * pagamento avulso. Quando o ciclo vence e nenhum pagamento aprovado chegou:
 *
 * - passados `grace_days` (3 por padrão) do fim do período → `past_due`. Isso **bloqueia
 *   o envio** de novos documentos (via `SubscriptionStatus::allowsSending()`) e não toca
 *   em mais nada: leitura, download, verificação pública e envelopes em andamento
 *   continuam funcionando;
 * - passados `expired_days` (15 por padrão) → `expired`, e a organização volta ao plano
 *   Grátis com um novo ciclo começando agora.
 *
 * ## Renovação do ciclo sem cobrança (plano Grátis)
 *
 * Uma assinatura de plano com `price_cents = 0` nunca gera pagamento, logo nunca passa por
 * `ActivateSubscription` — que é quem zera o consumo do ciclo. Sem um passo próprio, a cota
 * anunciada como mensal seria vitalícia. `renewFreeCycles()` faz esse passo (ver o método).
 *
 * Tudo idempotente: rodar o comando duas vezes no mesmo dia não muda nada duas vezes, e
 * cada transição é feita com `where(status = <estado esperado>)` para não atropelar uma
 * ativação que aconteceu no meio do caminho.
 */
class SubscriptionLifecycle
{
    public function __construct(private readonly BillingSettings $settings) {}

    // -- Ações do usuário -------------------------------------------------------------

    /**
     * Marca a assinatura para não renovar. O plano continua valendo até o fim do ciclo
     * já pago; depois disso a organização volta ao Grátis (pela expiração).
     */
    public function cancelAtPeriodEnd(Subscription $subscription): Subscription
    {
        if ($subscription->cancel_at_period_end) {
            return $subscription;
        }

        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'canceled_at' => Carbon::now(),
        ])->save();

        BillingTrail::record($subscription->organization_id, AuditEventType::SubscriptionCanceled, [
            'plan' => $subscription->plan->code,
            'period_end' => $subscription->current_period_end?->toIso8601String(),
        ]);

        return $subscription;
    }

    public function resume(Subscription $subscription): Subscription
    {
        if (! $subscription->cancel_at_period_end) {
            return $subscription;
        }

        $subscription->forceFill([
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ])->save();

        BillingTrail::record($subscription->organization_id, AuditEventType::SubscriptionResumed, [
            'plan' => $subscription->plan->code,
            'period_end' => $subscription->current_period_end?->toIso8601String(),
        ]);

        return $subscription;
    }

    // -- Comando agendado -------------------------------------------------------------

    /**
     * @return array{past_due: int, expired: int, renewed: int}
     */
    public function runDunning(?int $limit = null): array
    {
        return [
            'past_due' => $this->markPastDue($limit),
            'expired' => $this->expire($limit),
            'renewed' => $this->renewFreeCycles($limit),
        ];
    }

    /**
     * Renova o ciclo das assinaturas **sem cobrança recorrente** — hoje, o plano Grátis.
     *
     * A cota do Grátis é anunciada por ciclo ("5 documentos/mês") na tela de planos, no
     * fallback de criação da organização e na RECONCILIACAO §4 Q8, e a assinatura nasce com
     * `current_period_end = +1 mês`. Só que nada renovava esse ciclo: `applyCycle()` zera o
     * consumo apenas por pagamento aprovado (e o Grátis não gera pagamento) e
     * `markPastDue()` filtra `price_cents > 0`, então o `billing:dunning` nunca via essa
     * assinatura. Resultado: `envelopes_used` ficava travado e a cota mensal virava vitalícia.
     *
     * A regra aqui é a mesma de `ActivateSubscription::applyCycle()`: avança o período para o
     * ciclo corrente, zera `envelopes_used` e **reconta** `envelopes_reserved` a partir das
     * reservas ainda abertas no ledger (zerar às cegas perderia envelopes em trânsito).
     * Idempotente: a atualização exige `status = active` e o mesmo `current_period_end` que
     * foi lido, então duas execuções simultâneas não avançam o ciclo duas vezes.
     */
    public function renewFreeCycles(?int $limit = null): int
    {
        $now = Carbon::now();

        $query = Subscription::withoutOrganizationScope()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $now)
            ->whereHas('plan', fn ($plan) => $plan->where('price_cents', '<=', 0))
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $changed = 0;

        foreach ($query->with('plan')->get() as $subscription) {
            $previousEnd = $subscription->current_period_end;

            if ($previousEnd === null) {
                continue;
            }

            [$start, $end] = $this->nextCycle($subscription, $previousEnd, $now);

            $applied = Subscription::withoutOrganizationScope()
                ->whereKey($subscription->getKey())
                ->where('status', SubscriptionStatus::Active->value)
                ->where('current_period_end', $previousEnd)
                ->update([
                    'current_period_start' => $start,
                    'current_period_end' => $end,
                    'envelopes_used' => 0,
                    'envelopes_reserved' => $this->openReservations($subscription),
                    'updated_at' => $now,
                ]);

            if ($applied === 0) {
                continue;
            }

            $changed++;

            BillingTrail::record($subscription->organization_id, AuditEventType::SubscriptionRenewed, [
                'plan' => $subscription->plan->code,
                'period_start' => $start->toIso8601String(),
                'period_end' => $end->toIso8601String(),
            ]);

            Log::info('billing.subscription.cycle_renewed', [
                'organization_id' => $subscription->organization_id,
                'subscription' => $subscription->ulid,
                'plan' => $subscription->plan->code,
            ]);
        }

        return $changed;
    }

    /**
     * Ciclo corrente a partir do último vencido: avança de período em período até alcançar
     * "agora", para que uma organização parada por meses não ganhe um ciclo por execução.
     * O limite de 600 saltos existe só para que um dado corrompido (data de 1970) não vire
     * laço infinito; passado ele, o ciclo recomeça agora.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function nextCycle(Subscription $subscription, CarbonInterface $previousEnd, CarbonInterface $now): array
    {
        $yearly = $subscription->plan->billing_period->value === 'yearly';
        $start = $previousEnd->copy();

        for ($i = 0; $i < 600; $i++) {
            $end = $yearly ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();

            if ($end->greaterThan($now)) {
                return [$start, $end];
            }

            $start = $end;
        }

        $start = $now->copy();

        return [$start, $yearly ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow()];
    }

    /**
     * Reservas ainda abertas no ledger desta assinatura.
     */
    private function openReservations(Subscription $subscription): int
    {
        return (int) PlanConsumption::withoutOrganizationScope()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', PlanConsumptionStatus::Reserved->value)
            ->sum('quantity');
    }

    /**
     * Assinaturas pagas cujo ciclo venceu há mais de `grace_days` → `past_due`.
     */
    public function markPastDue(?int $limit = null): int
    {
        $cutoff = Carbon::now()->subDays($this->settings->graceDays());

        $query = Subscription::withoutOrganizationScope()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', $cutoff)
            // Fase 2, onda D: ciclo estornado por inteiro não é dívida — vai direto ao Grátis
            // (expireRefundedCycles). Sem a flag a coluna é sempre nula e nada muda.
            ->whereNull('paid_cycle_refunded_at')
            ->whereHas('plan', fn ($plan) => $plan->where('price_cents', '>', 0))
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $changed = 0;

        foreach ($query->with('plan')->get() as $subscription) {
            $applied = Subscription::withoutOrganizationScope()
                ->whereKey($subscription->getKey())
                ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
                ->whereNull('paid_cycle_refunded_at')
                ->update(['status' => SubscriptionStatus::PastDue->value, 'updated_at' => Carbon::now()]);

            if ($applied === 0) {
                continue;
            }

            $changed++;

            BillingTrail::record($subscription->organization_id, AuditEventType::SubscriptionPastDue, [
                'plan' => $subscription->plan->code,
                'period_end' => $subscription->current_period_end?->toIso8601String(),
                'grace_days' => $this->settings->graceDays(),
            ]);

            Log::info('billing.subscription.past_due', [
                'organization_id' => $subscription->organization_id,
                'subscription' => $subscription->ulid,
            ]);
        }

        return $changed;
    }

    /**
     * `past_due` há mais de `expired_days` → `expired` + volta ao plano Grátis.
     */
    public function expire(?int $limit = null): int
    {
        $cutoff = Carbon::now()->subDays($this->settings->expiredDays());

        $query = Subscription::withoutOrganizationScope()
            ->where('status', SubscriptionStatus::PastDue->value)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', $cutoff)
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $changed = 0;

        foreach ($query->with('plan')->get() as $subscription) {
            if ($this->expireOne($subscription)) {
                $changed++;
            }
        }

        return $changed + $this->expireRefundedCycles($limit);
    }

    /**
     * Fase 2, onda D — política conservadora de estorno total (docs/fase-2/pagamentos-e-fiscal.md
     * §4): a assinatura cujo ciclo pago foi estornado por inteiro vale até o fim do período e,
     * vencido ele, volta ao Grátis sem passar por `past_due` (não há dívida a cobrar). Só existe
     * assinatura com `paid_cycle_refunded_at` quando a flag `extended_payments` está ligada.
     */
    public function expireRefundedCycles(?int $limit = null): int
    {
        $query = Subscription::withoutOrganizationScope()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
            ->whereNotNull('paid_cycle_refunded_at')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', Carbon::now())
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $changed = 0;

        foreach ($query->with('plan')->get() as $subscription) {
            if ($this->expireOne($subscription, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], 'paid_cycle_refunded')) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @param  list<SubscriptionStatus>  $fromStatuses
     */
    private function expireOne(Subscription $subscription, array $fromStatuses = [SubscriptionStatus::PastDue], ?string $reason = null): bool
    {
        $free = Plan::query()->where('code', Plan::CODE_FREE)->first();

        if ($free === null) {
            Log::error('billing.subscription.expire_without_free_plan', [
                'alert' => 'billing_free_plan_missing',
                'subscription' => $subscription->ulid,
            ]);

            return false;
        }

        $planCode = $subscription->plan->code;

        $applied = DB::transaction(function () use ($subscription, $free, $fromStatuses): bool {
            $applied = Subscription::withoutOrganizationScope()
                ->whereKey($subscription->getKey())
                ->whereIn('status', array_map(static fn (SubscriptionStatus $status): string => $status->value, $fromStatuses))
                ->update([
                    'status' => SubscriptionStatus::Expired->value,
                    'canceled_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            if ($applied === 0) {
                return false;
            }

            $now = Carbon::now();

            // A organização volta ao Grátis com um ciclo novo: sem isso ela ficaria sem
            // assinatura vigente e nem sequer conseguiria ler o próprio plano.
            $replacement = new Subscription;
            $replacement->forceFill([
                'organization_id' => $subscription->organization_id,
                'plan_id' => $free->getKey(),
                'status' => SubscriptionStatus::Active,
                'started_at' => $now,
                'current_period_start' => $now,
                'current_period_end' => $now->copy()->addMonthNoOverflow(),
                'cancel_at_period_end' => false,
                'envelopes_used' => 0,
                'envelopes_reserved' => 0,
                'provider' => null,
            ]);
            $replacement->save();

            return true;
        });

        if (! $applied) {
            return false;
        }

        BillingTrail::record($subscription->organization_id, AuditEventType::SubscriptionExpired, $reason === null
            ? [
                'plan' => $planCode,
                'fallback_plan' => Plan::CODE_FREE,
                'expired_days' => $this->settings->expiredDays(),
            ]
            : [
                'plan' => $planCode,
                'fallback_plan' => Plan::CODE_FREE,
                'reason' => $reason,
            ]);

        Log::info('billing.subscription.expired', [
            'organization_id' => $subscription->organization_id,
            'subscription' => $subscription->ulid,
        ]);

        return true;
    }

    /**
     * Assinatura vigente de uma organização, sem depender da organização corrente.
     */
    public function currentFor(Organization $organization): ?Subscription
    {
        return Subscription::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::PastDue->value,
                SubscriptionStatus::Pending->value,
            ])
            ->latest('id')
            ->with('plan')
            ->first();
    }
}
