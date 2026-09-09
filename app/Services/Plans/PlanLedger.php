<?php

namespace App\Services\Plans;

use App\Enums\AuditEventType;
use App\Enums\PlanConsumptionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\PlanConsumption;
use App\Models\Subscription;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Plans\Exceptions\SendingBlockedException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ledger de consumo do plano (arquitetura §3.1 `plan_consumptions`, §3.3).
 *
 * Ciclo de vida de uma unidade de consumo:
 *
 *   reserved ──commit()──▶ committed        (envio concluído: convites despachados)
 *      │                       │
 *      └──────release()────────┴──▶ released (falha no despacho, ou cancelamento antes
 *                                             de qualquer assinatura)
 *
 * A idempotência é da COLUNA: `plan_consumptions.idempotency_key` é UNIQUE e a chave do
 * envio é `envelope:{id}:send`. Duas requisições concorrentes de envio produzem uma única
 * linha — a segunda recebe a existente e não decrementa a cota de novo.
 *
 * Os contadores desnormalizados (`subscriptions.envelopes_reserved` / `envelopes_used`)
 * são ajustados sob `lockForUpdate` da linha da assinatura (ou por `increment`, que é um
 * UPDATE relativo): duas transações concorrentes nunca perdem um incremento.
 */
class PlanLedger
{
    /**
     * Assinatura vigente da organização, com lock quando dentro de uma transação.
     */
    public function subscriptionFor(Organization|int $organization, bool $lock = false): ?Subscription
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        $query = Subscription::withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::PastDue->value,
                SubscriptionStatus::Pending->value,
            ])
            ->latest('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $subscription = $query->first();

        return $subscription?->load('plan');
    }

    /**
     * A organização pode enviar mais `$quantity` envelope(s)?
     *
     * @throws SendingBlockedException
     */
    public function assertCanSend(Subscription $subscription, int $quantity = 1): void
    {
        if ($subscription->status === SubscriptionStatus::PastDue) {
            throw SendingBlockedException::pastDue($subscription);
        }

        if (! $subscription->allowsSending()) {
            throw SendingBlockedException::subscriptionInactive($subscription);
        }

        if (! $subscription->hasEnvelopeQuotaAvailable($quantity)) {
            throw SendingBlockedException::quotaExhausted((int) $subscription->plan->envelope_quota);
        }
    }

    /**
     * Reserva `$quantity` unidade(s) para o envelope. Idempotente por `idempotency_key`:
     * se já existe reserva/consumo para a chave, devolve a linha existente sem cobrar de novo.
     *
     * Deve ser chamado DENTRO da transação do envio, com a assinatura já bloqueada.
     */
    public function reserve(Envelope $envelope, Subscription $subscription, int $quantity = 1): PlanConsumption
    {
        $key = PlanConsumption::sendKeyFor($envelope);

        $existing = $this->find($key);

        if ($existing !== null) {
            // Uma linha `released` é um envio que NÃO produziu efeito (o despacho falhou e
            // o envio foi desfeito). Devolvê-la como se fosse consumo válido deixava o
            // envelope atravessar o ciclo inteiro sem nunca descontar a cota: um novo
            // envio reabre a reserva.
            return $existing->status === PlanConsumptionStatus::Released
                ? $this->reopen($existing, $envelope, $subscription)
                : $existing;
        }

        try {
            $consumption = PlanConsumption::query()->create([
                'organization_id' => $envelope->organization_id,
                'subscription_id' => $subscription->getKey(),
                'envelope_id' => $envelope->getKey(),
                'idempotency_key' => $key,
                'quantity' => $quantity,
                'status' => PlanConsumptionStatus::Reserved,
                'reserved_at' => Carbon::now(),
            ]);
        } catch (QueryException $exception) {
            // Corrida perdida para outra requisição: a linha dela vale.
            $existing = $this->find($key);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        $subscription->newQuery()->whereKey($subscription->getKey())->increment('envelopes_reserved', $quantity);
        $subscription->envelopes_reserved += $quantity;

        EnvelopeAudit::record($envelope, AuditEventType::PlanConsumptionReserved, [
            'quantity' => $quantity,
            'plan' => $subscription->plan->code,
        ]);

        return $consumption;
    }

    /**
     * Confirma o consumo (reserva vira uso). Idempotente.
     */
    public function commit(PlanConsumption $consumption, ?Envelope $envelope = null): PlanConsumption
    {
        if ($consumption->status !== PlanConsumptionStatus::Reserved) {
            return $consumption;
        }

        $quantity = (int) $consumption->quantity;

        DB::transaction(function () use ($consumption, $quantity): void {
            $applied = PlanConsumption::query()
                ->whereKey($consumption->getKey())
                ->where('status', PlanConsumptionStatus::Reserved->value)
                ->update([
                    'status' => PlanConsumptionStatus::Committed->value,
                    'committed_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            if ($applied === 0) {
                return;
            }

            $subscription = Subscription::withoutOrganizationScope()
                ->whereKey($consumption->subscription_id)
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                return;
            }

            // Contadores atualizados sob lock da própria linha: sem SQL cru e sem perder
            // incrementos concorrentes.
            $subscription->forceFill([
                'envelopes_reserved' => max(0, (int) $subscription->envelopes_reserved - $quantity),
                'envelopes_used' => (int) $subscription->envelopes_used + $quantity,
            ])->save();
        });

        $consumption->refresh();

        if ($envelope !== null && $consumption->status === PlanConsumptionStatus::Committed) {
            EnvelopeAudit::record($envelope, AuditEventType::PlanConsumptionCommitted, [
                'quantity' => $consumption->quantity,
            ]);
        }

        return $consumption;
    }

    /**
     * Libera o consumo (falha no despacho, ou cancelamento antes de qualquer assinatura).
     * Aceita tanto `reserved` quanto `committed`. Idempotente.
     */
    public function release(PlanConsumption $consumption, ?Envelope $envelope = null, string $reason = 'released'): PlanConsumption
    {
        if ($consumption->status === PlanConsumptionStatus::Released) {
            return $consumption;
        }

        $from = $consumption->status;
        $quantity = (int) $consumption->quantity;

        DB::transaction(function () use ($consumption, $from, $quantity): void {
            $applied = PlanConsumption::query()
                ->whereKey($consumption->getKey())
                ->where('status', $from->value)
                ->update([
                    'status' => PlanConsumptionStatus::Released->value,
                    'released_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            if ($applied === 0) {
                return;
            }

            // Liberar uma reserva devolve cota reservada; liberar um consumo já confirmado
            // devolve cota usada.
            $column = $from === PlanConsumptionStatus::Reserved ? 'envelopes_reserved' : 'envelopes_used';

            $subscription = Subscription::withoutOrganizationScope()
                ->whereKey($consumption->subscription_id)
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                return;
            }

            $subscription->forceFill([
                $column => max(0, (int) $subscription->getAttribute($column) - $quantity),
            ])->save();
        });

        $consumption->refresh();

        if ($envelope !== null && $consumption->status === PlanConsumptionStatus::Released) {
            EnvelopeAudit::record($envelope, AuditEventType::PlanConsumptionReleased, [
                'quantity' => $consumption->quantity,
                'from' => $from->value,
                'reason' => $reason,
            ]);
        }

        return $consumption;
    }

    /**
     * Consumo do envio deste envelope, se existir.
     */
    public function forEnvelopeSend(Envelope $envelope): ?PlanConsumption
    {
        return $this->find(PlanConsumption::sendKeyFor($envelope));
    }

    /**
     * Reabre uma reserva liberada, devolvendo a linha ao estado `reserved` e recontando a
     * cota reservada. Idempotente: se outra requisição já reabriu, nada é cobrado de novo.
     */
    private function reopen(PlanConsumption $consumption, Envelope $envelope, Subscription $subscription): PlanConsumption
    {
        $quantity = (int) $consumption->quantity;

        $applied = PlanConsumption::query()
            ->whereKey($consumption->getKey())
            ->where('status', PlanConsumptionStatus::Released->value)
            ->update([
                'status' => PlanConsumptionStatus::Reserved->value,
                'subscription_id' => $subscription->getKey(),
                'reserved_at' => Carbon::now(),
                'released_at' => null,
                'committed_at' => null,
                'updated_at' => Carbon::now(),
            ]);

        $consumption->refresh();

        if ($applied === 0) {
            return $consumption;
        }

        $subscription->newQuery()->whereKey($subscription->getKey())->increment('envelopes_reserved', $quantity);
        $subscription->envelopes_reserved += $quantity;

        EnvelopeAudit::record($envelope, AuditEventType::PlanConsumptionReserved, [
            'quantity' => $quantity,
            'plan' => $subscription->plan->code,
            'reopened' => true,
        ]);

        return $consumption;
    }

    private function find(string $key): ?PlanConsumption
    {
        return PlanConsumption::withoutOrganizationScope()
            ->where('idempotency_key', $key)
            ->first();
    }
}
