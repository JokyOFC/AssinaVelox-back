<?php

namespace App\Services\Billing;

use App\Enums\PaymentEnvironment;
use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Models\PaymentChargeback;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Contestação avisada pelo tópico `chargebacks` — Fase 2, onda D.
 *
 * O aviso é só gatilho. A fonte da verdade são duas consultas: GET /v1/chargebacks/{id} (quais
 * pagamentos, valor, motivo, cobertura, documentação) e, para cada pagamento nosso,
 * GET /v1/payments/{id} via `SyncPaymentFromGateway` — é ela que grava `charged_back` e aplica o
 * efeito no plano e o alerta (`PaymentReversalEffects`). Um pagamento de outra instalação ou de
 * outro ambiente é ignorado.
 */
class SyncChargebackFromGateway
{
    public function __construct(
        private readonly CheckoutProGateway $gateway,
        private readonly SyncPaymentFromGateway $sync,
    ) {}

    /**
     * @return array{outcome: 'processed'|'ignored', reason: string|null, matched: int}
     *
     * @throws PaymentGatewayException quando inconclusivo (a fila tenta de novo)
     */
    public function handle(string $chargebackId): array
    {
        $remote = $this->gateway->getChargeback($chargebackId);
        $matched = 0;

        foreach ($remote->providerPaymentIds as $providerPaymentId) {
            $payment = Payment::withoutOrganizationScope()->where('provider_payment_id', $providerPaymentId)->first();

            if ($payment === null) {
                Log::info('billing.chargeback.unmatched_payment', ['chargeback' => $chargebackId, 'provider_payment_id' => $providerPaymentId]);

                continue;
            }

            if ($remote->liveMode !== ($payment->environment === PaymentEnvironment::Production)) {
                Log::warning('billing.chargeback.environment_mismatch', ['chargeback' => $chargebackId, 'payment' => $payment->ulid]);

                continue;
            }

            try {
                $this->sync->handle($providerPaymentId);
            } catch (PaymentGatewayException $exception) {
                if ($exception->inconclusive) {
                    throw $exception;
                }

                Log::warning('billing.chargeback.payment_sync_failed', ['payment' => $payment->ulid, 'error_code' => $exception->errorCode]);
            }

            $row = PaymentChargeback::withoutOrganizationScope()->firstOrNew([
                'provider' => $payment->provider,
                'provider_chargeback_id' => $remote->chargebackId,
                'payment_id' => $payment->getKey(),
            ]);

            $row->forceFill([
                'organization_id' => $payment->organization_id,
                'amount_cents' => $remote->amountCents,
                'currency' => $remote->currency,
                'reason' => $remote->reason,
                'coverage_applied' => $remote->coverageApplied,
                'documentation_status' => $remote->documentationStatus,
                'documentation_deadline_at' => $remote->documentationDeadline !== null ? Carbon::instance($remote->documentationDeadline) : null,
                'live_mode' => $remote->liveMode,
                'received_at' => $row->received_at ?? Carbon::now(),
                'last_synced_at' => Carbon::now(),
            ])->save();

            $matched++;
        }

        return $matched > 0
            ? ['outcome' => 'processed', 'reason' => null, 'matched' => $matched]
            : ['outcome' => 'ignored', 'reason' => 'payment_not_found', 'matched' => 0];
    }
}
