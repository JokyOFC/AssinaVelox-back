<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\Dto\GatewayRefund;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Jobs\Billing\ResolveUnknownRefund;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Billing\Exceptions\BillingActionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Estorno total ou parcial pelo Mercado Pago (POST /v1/payments/{id}/refunds) — Fase 2, onda D.
 *
 * ## Idempotência em três camadas
 *
 * 1. **Pedido**: o formulário manda uma chave (UUID) por pedido. Ela é gravada em
 *    `payment_refunds.idempotency_key` (UNIQUE) ANTES de qualquer chamada. Repetir o POST
 *    (duplo clique, reenvio do navegador) reencontra a linha e não chama o provedor de novo.
 * 2. **Provedor**: a mesma chave vai como `X-Idempotency-Key` (obrigatório nessa rota) em toda
 *    repetição, inclusive nas do cliente HTTP em 5xx/429.
 * 3. **Pagamento**: com um estorno aberto (`requested`, `pending`, `unknown`), um segundo pedido
 *    para o mesmo pagamento é recusado — nunca dois estornos concorrentes.
 *
 * ## Timeout = desconhecido (T5)
 *
 * Sem resposta conclusiva, a linha fica `unknown` e NADA é repetido às cegas: antes de repetir,
 * `resolveUnknown()` consulta GET /v1/payments/{id}/refunds. Achou um estorno que ainda não é
 * nosso, com o mesmo valor → é ele (adotado). Não achou → repete com a MESMA chave. A consulta
 * também falhou → continua `unknown` e o job tenta de novo mais tarde.
 *
 * ## Confirmação e efeito no plano
 *
 * A resposta do pedido atualiza a linha do estorno; o estado do PAGAMENTO (e o efeito no plano)
 * vem da consulta GET /v1/payments/{id} logo depois — a mesma fonte da verdade do webhook
 * (`SyncPaymentFromGateway` + `PaymentReversalEffects`).
 */
class RequestRefund
{
    public function __construct(
        private readonly CheckoutProGateway $gateway,
        private readonly BillingSettings $settings,
        private readonly SyncPaymentFromGateway $sync,
    ) {}

    /**
     * @param  int|null  $amountCents  null = estorno integral
     * @param  'platform_admin'|'owner'  $initiator
     *
     * @throws BillingActionException
     */
    public function handle(Payment $payment, ?int $amountCents, string $reason, User $actor, string $initiator, string $requestKey): PaymentRefund
    {
        if (! $this->settings->extendedPayments()) {
            throw BillingActionException::featureDisabled();
        }

        $existing = PaymentRefund::withoutOrganizationScope()->where('idempotency_key', $requestKey)->first();

        if ($existing !== null) {
            if ((int) $existing->payment_id !== (int) $payment->getKey()) {
                throw BillingActionException::idempotencyConflict();
            }

            // Repetição do MESMO pedido: nada de nova chamada. Um `unknown` é resolvido
            // consultando o provedor, nunca repetindo às cegas.
            return $existing->status === PaymentRefund::STATUS_UNKNOWN ? $this->resolveUnknown($existing) : $existing;
        }

        [$refund, $fresh] = DB::transaction(function () use ($payment, $amountCents, $reason, $actor, $initiator, $requestKey): array {
            $fresh = Payment::withoutOrganizationScope()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            $this->assertRefundable($fresh, $initiator);

            $remaining = $fresh->refundableCents();

            if ($remaining <= 0) {
                throw BillingActionException::nothingToRefund();
            }

            if ($amountCents !== null && ($amountCents < 1 || $amountCents > $remaining)) {
                throw BillingActionException::invalidAmount(Payment::formatBrl($remaining));
            }

            // Integral só quando nada foi estornado ainda — nem pela consulta (`refunded_cents`) nem
            // pelas linhas registradas aqui ({@see Payment::refundableCents()}): o corpo sem `amount`
            // é o estorno integral documentado. Depois de um parcial, o restante vai com valor explícito.
            $alreadyRefunded = (int) $fresh->amount_cents - $remaining;
            $total = $alreadyRefunded === 0 && ($amountCents === null || $amountCents === $remaining);

            if ($initiator === PaymentRefund::INITIATOR_OWNER && ! $total) {
                throw BillingActionException::ownerTotalOnly();
            }

            $refund = new PaymentRefund;
            $refund->forceFill([
                'organization_id' => $fresh->organization_id,
                'payment_id' => $fresh->getKey(),
                'provider' => $fresh->provider,
                'amount_cents' => $total ? $remaining : (int) ($amountCents ?? $remaining),
                'currency' => $fresh->currency,
                'kind' => $total ? PaymentRefund::KIND_TOTAL : PaymentRefund::KIND_PARTIAL,
                'status' => PaymentRefund::STATUS_REQUESTED,
                'reason' => Str::limit(trim($reason), 500, ''),
                'initiator' => $initiator,
                'requested_by_user_id' => $actor->getKey(),
                'idempotency_key' => $requestKey,
                'correlation_id' => (string) Str::ulid(),
                'requested_at' => Carbon::now(),
            ]);
            $refund->save();

            return [$refund, $fresh];
        });

        Log::info('billing.refund.requested', [
            'refund' => $refund->ulid,
            'payment' => $fresh->ulid,
            'kind' => $refund->kind,
            'amount_cents' => $refund->amount_cents,
            'currency' => $refund->currency,
            'initiator' => $initiator,
        ]);

        return $this->dispatchToProvider($refund, $fresh, scheduleResolution: true);
    }

    /**
     * Resolve um estorno sem confirmação: consulta ANTES de repetir.
     */
    public function resolveUnknown(PaymentRefund $refund): PaymentRefund
    {
        if ($refund->status !== PaymentRefund::STATUS_UNKNOWN) {
            return $refund;
        }

        $payment = Payment::withoutOrganizationScope()->findOrFail($refund->payment_id);

        try {
            $remoteRefunds = $this->gateway->listRefunds((string) $payment->provider_payment_id);
        } catch (PaymentGatewayException $exception) {
            Log::warning('billing.refund.lookup_failed', [
                'refund' => $refund->ulid,
                'error_code' => $exception->errorCode,
            ]);

            return $refund;
        }

        $known = PaymentRefund::withoutOrganizationScope()
            ->where('payment_id', $payment->getKey())
            ->whereKeyNot($refund->getKey())
            ->whereNotNull('provider_refund_id')
            ->pluck('provider_refund_id')
            ->all();

        foreach ($remoteRefunds as $remote) {
            if (in_array($remote->refundId, $known, true) || $remote->amountCents !== (int) $refund->amount_cents) {
                continue;
            }

            Log::info('billing.refund.recovered_after_timeout', [
                'refund' => $refund->ulid,
                'provider_refund_id' => $remote->refundId,
            ]);

            $this->applyRemote($refund, $remote);
            $this->syncPayment($payment);

            return $refund;
        }

        // O provedor não tem rastro do pedido: repetir é seguro, com a MESMA chave.
        return $this->dispatchToProvider($refund, $payment, scheduleResolution: false);
    }

    /**
     * @throws BillingActionException
     */
    private function assertRefundable(Payment $payment, string $initiator): void
    {
        if ($payment->provider !== $this->gateway->name()) {
            throw BillingActionException::providerMismatch();
        }

        if ($payment->provider_payment_id === null || $payment->status !== PaymentStatus::Approved) {
            throw BillingActionException::notRefundable();
        }

        $paidAt = $payment->paid_at ?? $payment->created_at;
        $maxAge = $this->settings->refundMaxAgeDays();

        if ($paidAt !== null && $paidAt->lt(Carbon::now()->subDays($maxAge))) {
            throw BillingActionException::tooOld($maxAge);
        }

        if ($initiator === PaymentRefund::INITIATOR_OWNER) {
            if (! $this->settings->ownerCanRequestRefund()) {
                throw BillingActionException::ownerNotAllowed();
            }

            $window = $this->settings->ownerRefundWindowDays();

            if ($paidAt !== null && $paidAt->lt(Carbon::now()->subDays($window))) {
                throw BillingActionException::ownerWindowExpired($window);
            }
        }

        $open = PaymentRefund::withoutOrganizationScope()
            ->where('payment_id', $payment->getKey())
            ->whereIn('status', PaymentRefund::OPEN_STATUSES)
            ->exists();

        if ($open) {
            throw BillingActionException::refundInProgress();
        }
    }

    private function dispatchToProvider(PaymentRefund $refund, Payment $payment, bool $scheduleResolution): PaymentRefund
    {
        try {
            $remote = $this->gateway->refundPayment(
                (string) $payment->provider_payment_id,
                $refund->kind === PaymentRefund::KIND_TOTAL ? null : (int) $refund->amount_cents,
                $refund->idempotency_key,
                $refund->correlation_id,
            );
        } catch (PaymentGatewayException $exception) {
            if ($exception->inconclusive) {
                $refund->forceFill([
                    'status' => PaymentRefund::STATUS_UNKNOWN,
                    'error' => 'inconclusive',
                ])->save();

                Log::warning('billing.refund.inconclusive', [
                    'refund' => $refund->ulid,
                    'correlation_id' => $exception->correlationId,
                    'status' => $exception->status,
                ]);

                if ($scheduleResolution) {
                    ResolveUnknownRefund::dispatch((int) $refund->getKey())->delay(Carbon::now()->addMinute());
                }

                return $refund->fresh() ?? $refund;
            }

            $refund->forceFill([
                'status' => PaymentRefund::STATUS_FAILED,
                'error' => Str::limit('rejected:'.($exception->status ?? '').':'.$exception->errorCode, 191, ''),
            ])->save();

            Log::warning('billing.refund.rejected', [
                'refund' => $refund->ulid,
                'status' => $exception->status,
                'error_code' => $exception->errorCode,
            ]);

            return $refund;
        }

        $this->applyRemote($refund, $remote);
        $this->syncPayment($payment);

        return $refund;
    }

    private function applyRemote(PaymentRefund $refund, GatewayRefund $remote): void
    {
        $status = match ($remote->status) {
            'approved' => PaymentRefund::STATUS_APPROVED,
            'rejected' => PaymentRefund::STATUS_REJECTED,
            'cancelled' => PaymentRefund::STATUS_CANCELLED,
            default => PaymentRefund::STATUS_PENDING, // in_process | authorized
        };

        $requestedCents = (int) $refund->amount_cents;

        if ($remote->amountCents !== $requestedCents) {
            Log::warning('billing.refund.amount_differs', [
                'refund' => $refund->ulid,
                'requested_cents' => $requestedCents,
                'provider_cents' => $remote->amountCents,
            ]);
        }

        $refund->forceFill([
            'provider_refund_id' => $remote->refundId,
            'provider_status' => $remote->status,
            'status' => $status,
            'error' => null,
            'confirmed_at' => $status === PaymentRefund::STATUS_APPROVED ? Carbon::now() : null,
            // O valor registrado é o que o provedor devolveu (não o que pedimos): o total estornado
            // nunca passa do valor pago por causa de um saldo local desatualizado.
            'amount_cents' => $remote->amountCents > 0 ? $remote->amountCents : $requestedCents,
        ])->save();
    }

    /**
     * O estado do pagamento (e o efeito no plano) vem da consulta, como no webhook. Se a consulta
     * falhar, o aviso `payment.updated` do provedor fará o mesmo trabalho depois.
     */
    private function syncPayment(Payment $payment): void
    {
        try {
            $this->sync->handle((string) $payment->provider_payment_id);
        } catch (PaymentGatewayException $exception) {
            Log::warning('billing.refund.sync_deferred', [
                'payment' => $payment->ulid,
                'error_code' => $exception->errorCode,
            ]);
        }
    }
}
