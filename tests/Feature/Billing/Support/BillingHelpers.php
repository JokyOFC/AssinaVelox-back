<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de cobrança (incremento 5)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\PaymentEnvironment;
use App\Enums\PaymentStatus;
use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\FakePaymentGateway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Testing\TestResponse;

if (! function_exists('billingSecret')) {
    /**
     * Configura a chave secreta do webhook (e o ambiente) para o teste corrente.
     */
    function billingSecret(string $secret = 'segredo-de-teste-do-webhook'): string
    {
        config()->set('assinavelox.mercadopago.webhook_secret', $secret);

        return $secret;
    }
}

if (! function_exists('useFakeGateway')) {
    /**
     * Amarra o dublê explícito no container e devolve a instância.
     */
    function useFakeGateway(): FakePaymentGateway
    {
        config()->set('assinavelox.mercadopago.driver', 'fake');

        $gateway = new FakePaymentGateway;

        app()->instance(FakePaymentGateway::class, $gateway);
        app()->instance(CheckoutProGateway::class, $gateway);

        return $gateway;
    }
}

if (! function_exists('mercadoPagoSignatureHeader')) {
    /**
     * Cabeçalho `x-signature` conforme o algoritmo oficial: manifesto
     * `id:<data.id>;request-id:<x-request-id>;ts:<ts>;` com HMAC-SHA256 hexadecimal.
     *
     * `$timestampMs` permite fabricar um carimbo fora da janela de tolerância.
     */
    function mercadoPagoSignatureHeader(string $dataId, string $requestId, string $secret, ?int $timestampMs = null): string
    {
        $ts = (string) ($timestampMs ?? (int) round(microtime(true) * 1000));
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";

        return 'ts='.$ts.',v1='.hash_hmac('sha256', $manifest, $secret);
    }
}

if (! function_exists('postMercadoPagoWebhook')) {
    /**
     * Entrega um aviso de webhook como o provedor entrega: `?data.id=…&type=payment` na
     * query string (é dali que o manifesto tira o `data.id`) e o JSON no corpo.
     *
     * @param  array<string, string|null>  $headers  sobrescreve/remove cabeçalhos (null remove)
     */
    function postMercadoPagoWebhook(
        string $dataId,
        ?string $secret,
        string $action = 'payment.updated',
        string $type = 'payment',
        ?string $requestId = null,
        ?int $timestampMs = null,
        array $headers = [],
    ): TestResponse {
        $requestId ??= 'req-'.substr(md5($dataId.$action), 0, 12);

        $sent = [];

        if ($secret !== null) {
            $sent['x-signature'] = mercadoPagoSignatureHeader($dataId, $requestId, $secret, $timestampMs);
            $sent['x-request-id'] = $requestId;
        }

        foreach ($headers as $name => $value) {
            if ($value === null) {
                unset($sent[$name]);

                continue;
            }

            $sent[$name] = $value;
        }

        $url = route('webhooks.mercadopago').'?data.id='.rawurlencode($dataId).'&type='.rawurlencode($type);

        return test()->postJson($url, [
            'id' => 1234567,
            'live_mode' => false,
            'type' => $type,
            'date_created' => now()->toIso8601String(),
            'user_id' => 987654,
            'api_version' => 'v1',
            'action' => $action,
            'data' => ['id' => $dataId],
        ], $sent);
    }
}

if (! function_exists('paidPlan')) {
    /**
     * Plano pago pronto para checkout (sandbox, como o seeder marca os pagos).
     */
    function paidPlan(int $priceCents = 4_900): Plan
    {
        return Plan::factory()->professional()->create(['price_cents' => $priceCents]);
    }
}

if (! function_exists('subscribeOrganization')) {
    /**
     * Substitui a assinatura vigente da organização por uma no plano informado.
     */
    function subscribeOrganization(Organization $organization, Plan $plan, string $state = 'active', int $used = 0): Subscription
    {
        Subscription::withoutOrganizationScope()
            ->where('organization_id', $organization->id)
            ->delete();

        $factory = Subscription::factory()->forOrganization($organization)->ofPlan($plan);

        $factory = match ($state) {
            'past_due' => $factory->pastDue(),
            'expired' => $factory->expired(),
            'canceled' => $factory->canceled(),
            default => $factory->active(),
        };

        return $factory->withUsage($used)->create();
    }
}

if (! function_exists('pendingPaymentFor')) {
    /**
     * Pagamento local `pending` como o checkout cria.
     */
    function pendingPaymentFor(Organization $organization, Plan $plan, ?Subscription $subscription = null): Payment
    {
        return Payment::factory()->create([
            'organization_id' => $organization->id,
            'subscription_id' => $subscription?->id,
            'plan_id' => $plan->id,
            'provider' => 'mercadopago',
            'status' => PaymentStatus::Pending,
            'amount_cents' => $plan->price_cents,
            'currency' => 'BRL',
            'environment' => PaymentEnvironment::Sandbox,
            'provider_payment_id' => null,
            'paid_at' => null,
            'activated_at' => null,
        ]);
    }
}
