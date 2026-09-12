<?php

namespace App\Services\Billing;

use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\Dto\GatewayRefund;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Conclui os estornos que o Mercado Pago aceitou como `in_process`/`authorized` (linha `pending`)
 * — Fase 2, onda D. Roda a cada consulta do pagamento (`SyncPaymentFromGateway`: webhook
 * `payment`, "Reconsultar pagamento", consulta logo depois de um estorno), só com a flag
 * `extended_payments` e só se houver linha `pending` para o pagamento.
 *
 * Duas fontes, nesta ordem — nenhuma inventa estado:
 *
 * 1. **GET /v1/payments/{id}/refunds**: o estorno com o mesmo `provider_refund_id` em
 *    `approved`, `rejected` ou `cancelled` fecha a linha com esse status (e com o valor que o
 *    provedor devolveu). Consulta falhou → segue para a fonte 2, e o que não fechar continua
 *    `pending` até a próxima consulta.
 * 2. **Total estornado da consulta do pagamento** (`payments.refunded_cents`, espelho de
 *    `transaction_amount_refunded`): se ele já cobre os estornos aprovados MAIS o valor de uma
 *    linha pendente, o dinheiro dela voltou — a linha é aprovada (na ordem em que foi pedida).
 */
final class RefreshPendingRefunds
{
    public function __construct(private readonly CheckoutProGateway $gateway) {}

    public function handle(Payment $payment): void
    {
        $pending = $this->pending($payment);

        if ($pending === []) {
            return;
        }

        $remote = $this->remoteRefunds($payment);

        foreach ($pending as $refund) {
            $match = $refund->provider_refund_id !== null ? ($remote[$refund->provider_refund_id] ?? null) : null;

            $status = match ($match?->status) {
                'approved' => PaymentRefund::STATUS_APPROVED,
                'rejected' => PaymentRefund::STATUS_REJECTED,
                'cancelled' => PaymentRefund::STATUS_CANCELLED,
                default => null, // in_process | authorized | não encontrado: ainda não se sabe
            };

            if ($status !== null) {
                $this->settle($refund, $status, $match->status, $match->amountCents, 'refund_lookup');
            }
        }

        $stillPending = $this->pending($payment);

        if ($stillPending === []) {
            return;
        }

        $approved = (int) PaymentRefund::withoutOrganizationScope()
            ->where('payment_id', $payment->getKey())
            ->where('status', PaymentRefund::STATUS_APPROVED)
            ->sum('amount_cents');

        $uncovered = (int) $payment->refunded_cents - $approved;

        foreach ($stillPending as $refund) {
            if ($uncovered < (int) $refund->amount_cents) {
                break;
            }

            $uncovered -= (int) $refund->amount_cents;
            $this->settle($refund, PaymentRefund::STATUS_APPROVED, 'approved', null, 'payment_refunded_total');
        }
    }

    /**
     * @return list<PaymentRefund>
     */
    private function pending(Payment $payment): array
    {
        return array_values(PaymentRefund::withoutOrganizationScope()
            ->where('payment_id', $payment->getKey())
            ->where('status', PaymentRefund::STATUS_PENDING)
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * @return array<string, GatewayRefund>
     */
    private function remoteRefunds(Payment $payment): array
    {
        if ($payment->provider !== $this->gateway->name() || $payment->provider_payment_id === null) {
            return [];
        }

        try {
            $list = $this->gateway->listRefunds((string) $payment->provider_payment_id);
        } catch (PaymentGatewayException $exception) {
            Log::warning('billing.refund.refresh_lookup_failed', [
                'payment' => $payment->ulid,
                'error_code' => $exception->errorCode,
            ]);

            return [];
        }

        $byId = [];

        foreach ($list as $refund) {
            $byId[$refund->refundId] = $refund;
        }

        return $byId;
    }

    private function settle(PaymentRefund $refund, string $status, string $providerStatus, ?int $providerCents, string $source): void
    {
        $refund->forceFill([
            'status' => $status,
            'provider_status' => $providerStatus,
            'confirmed_at' => $status === PaymentRefund::STATUS_APPROVED ? Carbon::now() : null,
            'amount_cents' => $providerCents !== null && $providerCents > 0 ? $providerCents : (int) $refund->amount_cents,
        ])->save();

        Log::info('billing.refund.settled', [
            'refund' => $refund->ulid,
            'status' => $status,
            'source' => $source,
        ]);
    }
}
