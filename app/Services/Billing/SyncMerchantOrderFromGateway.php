<?php

namespace App\Services\Billing;

use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Ordem comercial avisada pelo tópico `merchant_order` — Fase 2, onda D.
 *
 * No Checkout Pro uma preferência pode gerar vários pagamentos (boleto expirado + Pix pago). A
 * ordem (GET /merchant_orders/{id}) lista todos; cada um que seja nosso é sincronizado pela
 * consulta GET /v1/payments/{id}. Assim um pagamento cujo aviso `payment` se perdeu ainda chega.
 */
class SyncMerchantOrderFromGateway
{
    public function __construct(
        private readonly CheckoutProGateway $gateway,
        private readonly SyncPaymentFromGateway $sync,
    ) {}

    /**
     * @return array{outcome: 'processed'|'ignored', reason: string|null, matched: int}
     *
     * @throws PaymentGatewayException quando inconclusivo
     */
    public function handle(string $merchantOrderId): array
    {
        $order = $this->gateway->getMerchantOrder($merchantOrderId);
        $matched = 0;

        foreach ($order->payments as $payment) {
            if ($payment['id'] === '') {
                continue;
            }

            $ours = Payment::withoutOrganizationScope()->where('provider_payment_id', $payment['id'])->exists()
                || ($order->externalReference !== null && Payment::withoutOrganizationScope()->where('external_reference', $order->externalReference)->exists());

            if (! $ours) {
                continue;
            }

            try {
                $result = $this->sync->handle($payment['id']);
            } catch (PaymentGatewayException $exception) {
                if ($exception->inconclusive) {
                    throw $exception;
                }

                Log::warning('billing.merchant_order.payment_sync_failed', ['merchant_order' => $merchantOrderId, 'error_code' => $exception->errorCode]);

                continue;
            }

            if ($result['payment'] !== null) {
                $matched++;
            }
        }

        return $matched > 0
            ? ['outcome' => 'processed', 'reason' => null, 'matched' => $matched]
            : ['outcome' => 'ignored', 'reason' => 'payment_not_found', 'matched' => 0];
    }
}
