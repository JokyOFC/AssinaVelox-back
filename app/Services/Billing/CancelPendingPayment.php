<?php

namespace App\Services\Billing;

use App\Enums\AuditEventType;
use App\Enums\PaymentStatus;
use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Services\Billing\Exceptions\BillingActionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cancelamento de pagamento PENDENTE — Fase 2, onda D.
 *
 * Só `pending`, `in_process` e `authorized` (a referência de `PUT /v1/payments/{id}` aceita
 * exatamente esses; qualquer outro é o erro 2018). Aprovado não se cancela: desfaz-se por estorno.
 *
 * Dois casos:
 * - **sem pagamento no provedor** (o comprador abriu o checkout e não pagou): não há o que
 *   cancelar lá. O pagamento local vira `cancelled` (`status_detail = cancelled_before_payment`).
 *   A preferência expira sozinha na data de validade já enviada; se ainda assim um pagamento
 *   aprovado chegar por ela, o webhook o registra e a conciliação aponta ("aprovado depois de
 *   cancelado/expirado" é decisão de produto pendente, §4.5 item 28);
 * - **com pagamento no provedor** (Pix/boleto gerado): `PUT /v1/payments/{id}` com
 *   `status=cancelled` e `X-Idempotency-Key` estável por pagamento; o estado final vem da consulta
 *   GET /v1/payments/{id}. Timeout = desconhecido: nada muda aqui e o usuário é avisado.
 */
class CancelPendingPayment
{
    private const CANCELLABLE = [PaymentStatus::Pending, PaymentStatus::InProcess, PaymentStatus::Authorized];

    public function __construct(
        private readonly CheckoutProGateway $gateway,
        private readonly BillingSettings $settings,
        private readonly SyncPaymentFromGateway $sync,
    ) {}

    /**
     * @throws BillingActionException
     */
    public function handle(Payment $payment): Payment
    {
        if (! $this->settings->extendedPayments()) {
            throw BillingActionException::featureDisabled();
        }

        if (! in_array($payment->status, self::CANCELLABLE, true)) {
            throw BillingActionException::notCancellable();
        }

        if ($payment->provider_payment_id === null) {
            return $this->cancelLocally($payment);
        }

        if ($payment->provider !== $this->gateway->name()) {
            throw BillingActionException::providerMismatch();
        }

        try {
            $this->gateway->cancelPayment((string) $payment->provider_payment_id, 'cancel-'.$payment->ulid, $payment->ulid);
        } catch (PaymentGatewayException $exception) {
            if ($exception->inconclusive) {
                Log::warning('billing.cancel.inconclusive', ['payment' => $payment->ulid, 'correlation_id' => $exception->correlationId]);

                throw BillingActionException::cancelInconclusive();
            }

            Log::warning('billing.cancel.rejected', ['payment' => $payment->ulid, 'status' => $exception->status, 'error_code' => $exception->errorCode]);

            $this->refresh($payment);

            throw BillingActionException::cancelRejected();
        }

        $this->refresh($payment);

        return $payment->refresh();
    }

    private function cancelLocally(Payment $payment): Payment
    {
        $applied = DB::transaction(function () use ($payment): bool {
            $fresh = Payment::withoutOrganizationScope()->whereKey($payment->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! in_array($fresh->status, self::CANCELLABLE, true) || $fresh->provider_payment_id !== null) {
                return false;
            }

            $fresh->forceFill([
                'status' => PaymentStatus::Cancelled,
                'status_detail' => 'cancelled_before_payment',
                'cancelled_at' => Carbon::now(),
            ])->save();

            return true;
        });

        if (! $applied) {
            throw BillingActionException::notCancellable();
        }

        BillingTrail::record($payment->organization_id, AuditEventType::PaymentFailed, [
            'payment' => $payment->ulid,
            'status' => PaymentStatus::Cancelled->value,
            'status_detail' => 'cancelled_before_payment',
        ]);

        return $payment->refresh();
    }

    private function refresh(Payment $payment): void
    {
        try {
            $this->sync->handle((string) $payment->provider_payment_id);
        } catch (PaymentGatewayException $exception) {
            Log::warning('billing.cancel.sync_deferred', ['payment' => $payment->ulid, 'error_code' => $exception->errorCode]);
        }
    }
}
