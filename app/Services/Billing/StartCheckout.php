<?php

namespace App\Services\Billing;

use App\Enums\AuditEventType;
use App\Enums\PaymentStatus;
use App\Integrations\Dto\CheckoutPreference;
use App\Integrations\Dto\CheckoutPreferenceRequest;
use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\Exceptions\CheckoutException;
use App\Services\Plans\PlanLedger;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Início do Checkout Pro (ROUTES §2.15 / §2.16).
 *
 * Sequência:
 *
 * 1. cria (ou reaproveita) um `Payment` local `pending` com o NOSSO `external_reference`
 *    — um ULID de 26 caracteres, dentro do limite de 64 e do alfabeto aceito pelo campo;
 * 2. pede a preferência ao gateway, com valor em **centavos inteiros** convertidos na
 *    borda e moeda explícita;
 * 3. guarda `provider_preference_id` e `checkout_url` (o `init_point`, nunca o
 *    `sandbox_init_point`) e devolve o pagamento para o controller redirecionar.
 *
 * ## Ambiguidade de timeout
 *
 * Se a criação da preferência não conclui (conexão caiu, 5xx, 429 depois das
 * repetições), **não sabemos** se o provedor criou a preferência. O pagamento local
 * fica `pending` sem `provider_preference_id` e o serviço lança
 * `CheckoutException::inconclusive()`. Na tentativa seguinte, antes de criar qualquer
 * coisa, consultamos `GET /checkout/preferences/search?external_reference=…`: se a
 * preferência existir, ela é reaproveitada. Só quando a consulta responde "não existe" é
 * que criamos outra. Resultado: uma tentativa que deu timeout nunca vira duas cobranças.
 *
 * ## Reaproveitamento
 *
 * Um pagamento `pending` do mesmo plano, criado dentro da validade da preferência
 * (`preference_ttl_hours`), é reaproveitado — dois cliques no botão levam ao mesmo
 * checkout, não a duas cobranças.
 */
class StartCheckout
{
    public function __construct(
        private readonly CheckoutProGateway $gateway,
        private readonly BillingSettings $settings,
        private readonly PlanLedger $ledger,
    ) {}

    /**
     * @throws CheckoutException
     */
    public function handle(Organization $organization, Plan $plan, ?User $actor = null): Payment
    {
        if ($plan->isFree() || ! $plan->is_active) {
            throw CheckoutException::planNotPayable();
        }

        if (! $this->gateway->isConfigured()) {
            throw CheckoutException::gatewayDisabled();
        }

        $payment = $this->reusableOrNewPayment($organization, $plan);

        if ($payment->checkout_url !== null && $payment->provider_preference_id !== null) {
            return $payment;
        }

        $preference = $this->resolvePreference($payment, $organization, $plan, $actor);

        $payment->forceFill([
            'provider_preference_id' => $preference->preferenceId,
            'checkout_url' => $preference->checkoutUrl,
        ])->save();

        return $payment;
    }

    /**
     * Pagamento `pending` reaproveitável, ou um novo em `pending`.
     */
    private function reusableOrNewPayment(Organization $organization, Plan $plan): Payment
    {
        $ttlStart = Carbon::now()->subHours($this->settings->preferenceTtlHours());

        $existing = Payment::forOrganization($organization)
            ->where('plan_id', $plan->getKey())
            ->where('provider', $this->gateway->name())
            ->where('status', PaymentStatus::Pending->value)
            ->whereNull('activated_at')
            ->where('created_at', '>=', $ttlStart)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $subscription = $this->ledger->subscriptionFor($organization);

        $payment = new Payment;
        $payment->forceFill([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $subscription?->getKey(),
            'plan_id' => $plan->getKey(),
            'provider' => $this->gateway->name(),
            'status' => PaymentStatus::Pending,
            'status_detail' => 'checkout_started',
            'amount_cents' => (int) $plan->price_cents,
            'currency' => $plan->currency,
            'environment' => $this->gateway->environment(),
        ]);
        $payment->save();

        BillingTrail::record($organization, AuditEventType::PaymentCreated, [
            'payment' => $payment->ulid,
            'plan' => $plan->code,
            'amount_cents' => (int) $plan->price_cents,
            'currency' => $plan->currency,
            'environment' => $this->gateway->environment()->value,
            'provider' => $this->gateway->name(),
        ]);

        return $payment;
    }

    /**
     * Consulta antes de recriar; cria só quando a consulta diz que não existe.
     *
     * @throws CheckoutException
     */
    private function resolvePreference(Payment $payment, Organization $organization, Plan $plan, ?User $actor): CheckoutPreference
    {
        // Chegamos aqui com um pagamento local que já existia e ficou sem preferência:
        // é exatamente o rastro de uma criação inconclusiva. Consultar primeiro.
        if ($payment->wasRecentlyCreated === false) {
            $found = $this->findExistingPreference($payment);

            if ($found !== null) {
                Log::info('billing.checkout.preference_recovered', [
                    'payment' => $payment->ulid,
                    'preference' => $found->preferenceId,
                ]);

                return $found;
            }
        }

        try {
            return $this->gateway->createCheckoutPreference($this->preferenceRequest($payment, $organization, $plan, $actor));
        } catch (PaymentGatewayException $exception) {
            if ($exception->inconclusive) {
                Log::warning('billing.checkout.inconclusive', [
                    'payment' => $payment->ulid,
                    'correlation_id' => $exception->correlationId,
                    'status' => $exception->status,
                ]);

                // O pagamento local fica `pending` sem preferência de propósito: é ele
                // que faz a próxima tentativa consultar antes de criar.
                throw CheckoutException::inconclusive();
            }

            Log::warning('billing.checkout.rejected', [
                'payment' => $payment->ulid,
                'error_code' => $exception->errorCode,
                'status' => $exception->status,
            ]);

            throw CheckoutException::rejected();
        }
    }

    /**
     * Uma consulta que também falha não pode virar "não existe" — nesse caso pedimos
     * para tentar de novo mais tarde, nunca criamos uma segunda preferência.
     *
     * @throws CheckoutException
     */
    private function findExistingPreference(Payment $payment): ?CheckoutPreference
    {
        try {
            return $this->gateway->findPreferenceByExternalReference($payment->external_reference);
        } catch (PaymentGatewayException $exception) {
            Log::warning('billing.checkout.lookup_failed', [
                'payment' => $payment->ulid,
                'error_code' => $exception->errorCode,
            ]);

            throw CheckoutException::inconclusive();
        }
    }

    private function preferenceRequest(Payment $payment, Organization $organization, Plan $plan, ?User $actor): CheckoutPreferenceRequest
    {
        $expiresAt = Carbon::now()->addHours($this->settings->preferenceTtlHours());

        return new CheckoutPreferenceRequest(
            externalReference: $payment->external_reference,
            title: 'AssinaVelox — plano '.$plan->name,
            amountCents: (int) $payment->amount_cents,
            successUrl: route('billing.return', ['outcome' => 'success']),
            failureUrl: route('billing.return', ['outcome' => 'failure']),
            pendingUrl: route('billing.return', ['outcome' => 'pending']),
            notificationUrl: $this->settings->notificationUrl() ?? route('webhooks.mercadopago'),
            currency: $payment->currency,
            quantity: 1,
            description: 'Assinatura mensal do plano '.$plan->name.' na AssinaVelox.',
            payerEmail: $actor?->email,
            statementDescriptor: $this->settings->statementDescriptor(),
            expiresAt: new DateTimeImmutable($expiresAt->toIso8601String()),
            metadata: [
                // Metadados sem dado pessoal: identificadores públicos, nada mais.
                'organization' => $organization->ulid,
                'payment' => $payment->ulid,
                'plan' => $plan->code,
                'environment' => $this->gateway->environment()->value,
            ],
            correlationId: $payment->ulid,
        );
    }
}
