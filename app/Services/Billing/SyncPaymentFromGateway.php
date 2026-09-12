<?php

namespace App\Services\Billing;

use App\Enums\AuditEventType;
use App\Enums\PaymentEnvironment;
use App\Enums\PaymentStatus;
use App\Integrations\Dto\GatewayPayment;
use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\MercadoPagoGateway;
use App\Jobs\Billing\IssueFiscalInvoiceJob;
use App\Models\Payment;
use App\Services\Fiscal\FiscalFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza um pagamento local com o estado real no provedor.
 *
 * **A fonte da verdade é a consulta, nunca o payload do webhook.** O aviso do Mercado
 * Pago é só um gatilho que diz "olhe o pagamento X"; quem decide é
 * `GET /v1/payments/{id}`, feito com o nosso access token.
 *
 * Antes de aplicar qualquer coisa, quatro conferências — todas com o mesmo espírito:
 * um evento que não bate com o que pedimos não pode virar plano ativo.
 *
 * 1. **Existe do nosso lado?** casamos por `external_reference` (o ULID que criamos) e,
 *    como rede de segurança, por `provider_payment_id`. Sem correspondência, o evento é
 *    ignorado — pode ser de outra instalação apontando para o mesmo webhook.
 * 2. **Ambiente.** `live_mode` da resposta tem que corresponder ao ambiente do
 *    pagamento local (`sandbox` × `production`). Sandbox e produção nunca se misturam.
 * 3. **Valor e moeda.** o valor cobrado tem que ser o valor que pedimos, na mesma moeda.
 *    Divergência não ativa nada e é registrada como falha para inspeção humana.
 * 4. **Ordem dos eventos.** ver `PaymentStatusTransition`: um evento antigo nunca
 *    rebaixa um pagamento já aprovado.
 *
 * Só depois disso, e só com `approved`, o serviço chama `ActivateSubscription` — que é
 * idempotente por conta própria.
 */
class SyncPaymentFromGateway
{
    public function __construct(
        private readonly CheckoutProGateway $gateway,
        private readonly ActivateSubscription $activator,
        // Fase 2, onda D: só usados com a flag `extended_payments` (e `fiscal_invoices`) ligada.
        private readonly ?PaymentReversalEffects $effects = null,
        private readonly ?BillingSettings $settings = null,
    ) {}

    private function extended(): bool
    {
        return ($this->settings ?? app(BillingSettings::class))->extendedPayments();
    }

    /**
     * @return array{outcome: string, payment: Payment|null, reason: string|null}
     */
    public function handle(string $providerPaymentId): array
    {
        $remote = $this->gateway->getPayment($providerPaymentId);

        $payment = $this->locate($remote);

        if ($payment === null) {
            Log::info('billing.payment.sync_unmatched', [
                'provider_payment_id' => $providerPaymentId,
                'external_reference' => $remote->externalReference,
            ]);

            return ['outcome' => 'ignored', 'payment' => null, 'reason' => 'payment_not_found'];
        }

        $expectedLiveMode = $payment->environment === PaymentEnvironment::Production;

        if ($remote->liveMode !== $expectedLiveMode) {
            Log::warning('billing.payment.environment_mismatch', [
                'payment' => $payment->ulid,
                'expected_environment' => $payment->environment->value,
                'remote_live_mode' => $remote->liveMode,
            ]);

            return ['outcome' => 'ignored', 'payment' => $payment, 'reason' => 'environment_mismatch'];
        }

        if ($remote->amountCents !== (int) $payment->amount_cents || $remote->currency !== $payment->currency) {
            Log::error('billing.payment.amount_mismatch', [
                'alert' => 'billing_amount_mismatch',
                'payment' => $payment->ulid,
                'expected_amount_cents' => (int) $payment->amount_cents,
                'expected_currency' => $payment->currency,
                'remote_amount_cents' => $remote->amountCents,
                'remote_currency' => $remote->currency,
            ]);

            return ['outcome' => 'failed', 'payment' => $payment, 'reason' => 'amount_mismatch'];
        }

        $incoming = PaymentStatus::tryFrom($remote->status);

        if ($incoming === null) {
            Log::warning('billing.payment.unknown_status', [
                'payment' => $payment->ulid,
                'remote_status' => $remote->status,
            ]);

            return ['outcome' => 'ignored', 'payment' => $payment, 'reason' => 'unknown_status'];
        }

        if (PaymentStatusTransition::isOutOfOrder($payment->status, $incoming)) {
            Log::info('billing.payment.out_of_order', [
                'payment' => $payment->ulid,
                'current' => $payment->status->value,
                'incoming' => $incoming->value,
            ]);

            return ['outcome' => 'ignored', 'payment' => $payment, 'reason' => 'out_of_order'];
        }

        $previous = $payment->status;
        $this->apply($payment, $remote, $incoming);

        if ($this->extended()) {
            // Estornos aceitos como `in_process`/`authorized` são concluídos por esta consulta
            // (sem linha `pending`, nada é consultado).
            app(RefreshPendingRefunds::class)->handle($payment);
        }

        if ($this->extended() && $previous !== $incoming) {
            // Estorno total e contestação só têm efeito no plano depois desta CONSULTA.
            ($this->effects ?? app(PaymentReversalEffects::class))->afterTransition($payment, $previous, $incoming);
        }

        if ($incoming === PaymentStatus::Approved) {
            if ($previous !== PaymentStatus::Approved) {
                BillingTrail::record($payment->organization_id, AuditEventType::PaymentApproved, [
                    'payment' => $payment->ulid,
                    'provider_payment_id' => $payment->provider_payment_id,
                    'amount_cents' => (int) $payment->amount_cents,
                    'currency' => $payment->currency,
                    'payment_method_id' => $payment->payment_method_id,
                    'environment' => $payment->environment->value,
                ]);
            }

            $this->activator->handle($payment);

            if ($previous !== PaymentStatus::Approved && FiscalFeature::enabled()) {
                // Roadmap §2.21: NFS-e disparada em `payments.approved`, idempotente por pagamento.
                IssueFiscalInvoiceJob::dispatch((int) $payment->getKey());
            }

            return ['outcome' => 'processed', 'payment' => $payment, 'reason' => null];
        }

        if ($incoming->isTerminal() && $previous !== $incoming) {
            BillingTrail::record($payment->organization_id, AuditEventType::PaymentFailed, [
                'payment' => $payment->ulid,
                'status' => $incoming->value,
                'status_detail' => $payment->status_detail,
            ]);
        }

        return ['outcome' => 'processed', 'payment' => $payment, 'reason' => null];
    }

    /**
     * Casa o pagamento remoto com o nosso. O `external_reference` é a chave primária
     * desse casamento; o id do provedor é a rede de segurança para reentregas.
     */
    private function locate(GatewayPayment $remote): ?Payment
    {
        if ($remote->externalReference !== null && $remote->externalReference !== '') {
            $payment = Payment::withoutOrganizationScope()
                ->where('external_reference', $remote->externalReference)
                ->first();

            if ($payment !== null) {
                return $payment;
            }
        }

        return Payment::withoutOrganizationScope()
            ->where('provider_payment_id', $remote->providerPaymentId)
            ->first();
    }

    /**
     * Fase 2, onda D: o que a consulta traz além do Fase 1 — família do meio, soma estornada,
     * validade do Pix/boleto e última atualização no provedor. Tudo lido do `raw` já saneado
     * (sem pagador e sem cartão).
     *
     * @return array<string, mixed>
     */
    private function extendedAttributes(Payment $payment, GatewayPayment $remote, PaymentStatus $status): array
    {
        $raw = $remote->raw;
        $attributes = [];

        if (is_string($raw['payment_type_id'] ?? null) && $raw['payment_type_id'] !== '') {
            $attributes['payment_type_id'] = mb_substr($raw['payment_type_id'], 0, 32);
        }

        if (array_key_exists('transaction_amount_refunded', $raw)) {
            $attributes['refunded_cents'] = min((int) $payment->amount_cents, MercadoPagoGateway::toCents($raw['transaction_amount_refunded']));
        } elseif ($status === PaymentStatus::Refunded) {
            $attributes['refunded_cents'] = (int) $payment->amount_cents;
        }

        foreach (['expires_at' => 'date_of_expiration', 'provider_updated_at' => 'date_last_updated'] as $column => $key) {
            if (is_string($raw[$key] ?? null) && $raw[$key] !== '') {
                try {
                    $attributes[$column] = Carbon::parse($raw[$key]);
                } catch (\Throwable) {
                    // Data fora do formato: ignorada, nunca inventada.
                }
            }
        }

        if ($status === PaymentStatus::Cancelled && $payment->cancelled_at === null) {
            $attributes['cancelled_at'] = Carbon::now();
        }

        return $attributes;
    }

    private function apply(Payment $payment, GatewayPayment $remote, PaymentStatus $status): void
    {
        DB::transaction(function () use ($payment, $remote, $status): void {
            $attributes = [
                'status' => $status,
                'status_detail' => $remote->statusDetail,
                'provider_payment_id' => $remote->providerPaymentId,
                'payment_method_id' => $remote->paymentMethodId,
                // Somente o e-mail mascarado: nenhum dado pessoal completo, nenhum dado
                // de cartão (o Checkout Pro não nos entrega nenhum, e não guardaríamos).
                'payer_email_masked' => $remote->payerEmailMasked ?? $payment->payer_email_masked,
            ];

            if ($status === PaymentStatus::Approved) {
                $attributes['paid_at'] = $remote->approvedAt !== null
                    ? Carbon::instance($remote->approvedAt)
                    : ($payment->paid_at ?? Carbon::now());
            }

            if ($this->extended()) {
                $attributes = [...$attributes, ...$this->extendedAttributes($payment, $remote, $status)];
            }

            $payment->forceFill($attributes)->save();
        });
    }
}
