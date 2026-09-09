<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Integrations\Dto\CheckoutPreferenceRequest;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\MercadoPagoGateway;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Billing\Exceptions\CheckoutException;
use App\Services\Billing\StartCheckout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/BillingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->gateway = useFakeGateway();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->owner = $owner;
    $this->plan = paidPlan();
});

/*
|--------------------------------------------------------------------------
| Abrir o checkout
|--------------------------------------------------------------------------
*/

test('o checkout cria um pagamento pending com referência externa própria e redireciona para o init_point', function () {
    actingAsMember($this->owner, $this->organization);

    $response = $this->post(route('billing.checkout'), ['plan' => $this->plan->code, 'interval' => 'monthly']);

    $payment = Payment::withoutOrganizationScope()->firstOrFail();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->activated_at)->toBeNull()
        ->and($payment->external_reference)->toHaveLength(26)
        ->and(strlen($payment->external_reference))->toBeLessThanOrEqual(64)
        ->and($payment->external_reference)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and($payment->amount_cents)->toBe((int) $this->plan->price_cents)
        ->and($payment->currency)->toBe('BRL')
        ->and($payment->checkout_url)->toStartWith(FakePaymentGateway::CHECKOUT_HOST);

    // Fora de uma visita Inertia, Inertia::location é um 302 comum.
    $response->assertRedirect($payment->checkout_url);
});

test('numa visita Inertia o redirecionamento externo sai como 409 + X-Inertia-Location', function () {
    actingAsMember($this->owner, $this->organization);

    $response = $this->post(
        route('billing.checkout'),
        ['plan' => $this->plan->code, 'interval' => 'monthly'],
        ['X-Inertia' => 'true', 'X-Inertia-Version' => '1'],
    );

    $payment = Payment::withoutOrganizationScope()->firstOrFail();

    $response->assertStatus(409)->assertHeader('x-inertia-location', $payment->checkout_url);
});

test('o pedido de preferência leva valor em centavos, moeda explícita e a nossa referência', function () {
    actingAsMember($this->owner, $this->organization);

    $this->post(route('billing.checkout'), ['plan' => $this->plan->code, 'interval' => 'monthly']);

    $payment = Payment::withoutOrganizationScope()->firstOrFail();
    $request = $this->gateway->preferenceRequests()[0];

    expect($request->amountCents)->toBe(4_900)
        ->and($request->currency)->toBe('BRL')
        ->and($request->externalReference)->toBe($payment->external_reference)
        ->and($request->notificationUrl)->toBe(route('webhooks.mercadopago'))
        ->and($request->successUrl)->toBe(route('billing.return', ['outcome' => 'success']))
        ->and($request->pendingUrl)->toBe(route('billing.return', ['outcome' => 'pending']))
        ->and($request->failureUrl)->toBe(route('billing.return', ['outcome' => 'failure']))
        ->and($request->metadata)->toMatchArray([
            'organization' => $this->organization->ulid,
            'plan' => $this->plan->code,
        ]);
});

test('dois cliques seguidos reaproveitam a mesma preferência em vez de criar duas cobranças', function () {
    actingAsMember($this->owner, $this->organization);

    $this->post(route('billing.checkout'), ['plan' => $this->plan->code, 'interval' => 'monthly']);
    $this->post(route('billing.checkout'), ['plan' => $this->plan->code, 'interval' => 'monthly']);

    expect(Payment::withoutOrganizationScope()->count())->toBe(1)
        ->and($this->gateway->preferenceRequestCount())->toBe(1);
});

test('o plano gratuito não vai para o checkout', function () {
    actingAsMember($this->owner, $this->organization);

    $this->post(route('billing.checkout'), ['plan' => Plan::CODE_FREE, 'interval' => 'monthly'])
        ->assertSessionHasErrors('plan');

    expect(Payment::withoutOrganizationScope()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Ambiguidade de timeout
|--------------------------------------------------------------------------
| Uma resposta inconclusiva não é sucesso nem falha. O pagamento local fica
| pending sem preferência; a tentativa seguinte CONSULTA o provedor antes de
| criar qualquer coisa.
*/

test('timeout na criação da preferência não gera pagamento duplicado: a segunda tentativa consulta antes de recriar', function () {
    $checkout = app(StartCheckout::class);

    // 1ª tentativa: a preferência é criada no provedor, mas a resposta se perde.
    $this->gateway->failNextWith(PaymentGatewayException::inconclusive('create_preference', 'corr-1'));

    expect(fn () => $checkout->handle($this->organization, $this->plan, $this->owner))
        ->toThrow(CheckoutException::class);

    $payment = Payment::withoutOrganizationScope()->firstOrFail();

    expect(Payment::withoutOrganizationScope()->count())->toBe(1)
        ->and($payment->provider_preference_id)->toBeNull()
        ->and($payment->checkout_url)->toBeNull()
        ->and($payment->status)->toBe(PaymentStatus::Pending);

    // O provedor, na verdade, tinha criado a preferência para a nossa referência.
    $recovered = $this->gateway->createCheckoutPreference(
        new CheckoutPreferenceRequest(
            externalReference: $payment->external_reference,
            title: 'AssinaVelox',
            amountCents: (int) $this->plan->price_cents,
            successUrl: 'https://exemplo.test/s',
            failureUrl: 'https://exemplo.test/f',
            pendingUrl: 'https://exemplo.test/p',
        ),
    );

    $countAfterProviderSide = $this->gateway->preferenceRequestCount();

    // 2ª tentativa: consulta, encontra a preferência e reaproveita — sem criar outra.
    $again = $checkout->handle($this->organization, $this->plan, $this->owner);

    expect(Payment::withoutOrganizationScope()->count())->toBe(1)
        ->and($again->getKey())->toBe($payment->getKey())
        ->and($again->provider_preference_id)->toBe($recovered->preferenceId)
        ->and($this->gateway->preferenceRequestCount())->toBe($countAfterProviderSide);
});

test('quando a consulta também falha, nada é recriado — o usuário é convidado a tentar de novo', function () {
    $checkout = app(StartCheckout::class);

    $this->gateway->failNextWith(PaymentGatewayException::inconclusive('create_preference'));
    expect(fn () => $checkout->handle($this->organization, $this->plan, $this->owner))->toThrow(CheckoutException::class);

    $created = $this->gateway->preferenceRequestCount();

    $this->gateway->failNextWith(PaymentGatewayException::inconclusive('search_preference'));
    expect(fn () => $checkout->handle($this->organization, $this->plan, $this->owner))->toThrow(CheckoutException::class);

    expect(Payment::withoutOrganizationScope()->count())->toBe(1)
        ->and($this->gateway->preferenceRequestCount())->toBe($created);
});

test('quando o provedor confirma que a preferência não existe, uma nova é criada para o mesmo pagamento', function () {
    $checkout = app(StartCheckout::class);

    $this->gateway->failNextWith(PaymentGatewayException::inconclusive('create_preference'));
    expect(fn () => $checkout->handle($this->organization, $this->plan, $this->owner))->toThrow(CheckoutException::class);

    $payment = Payment::withoutOrganizationScope()->firstOrFail();
    $this->gateway->forgetPreference($payment->external_reference);

    $again = $checkout->handle($this->organization, $this->plan, $this->owner);

    expect(Payment::withoutOrganizationScope()->count())->toBe(1)
        ->and($again->getKey())->toBe($payment->getKey())
        ->and($again->checkout_url)->not->toBeNull();
});

test('o adaptador real trata timeout de conexão como inconclusivo, não como falha', function () {
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token-de-teste');
    config()->set('assinavelox.mercadopago.retries', 0);

    Http::fake(fn () => throw new ConnectionException('timeout'));

    $gateway = app(MercadoPagoGateway::class);

    try {
        $gateway->getPayment('123');
        $this->fail('esperava PaymentGatewayException');
    } catch (PaymentGatewayException $exception) {
        expect($exception->inconclusive)->toBeTrue()
            ->and($exception->errorCode)->toBe('inconclusive');
    }
});

/*
|--------------------------------------------------------------------------
| O retorno do checkout é APENAS informativo
|--------------------------------------------------------------------------
*/

test('o retorno do checkout, sozinho, não ativa plano nenhum', function () {
    $subscription = subscribeOrganization($this->organization, Plan::free() ?? Plan::factory()->free()->create());
    $payment = pendingPaymentFor($this->organization, $this->plan, $subscription);

    actingAsMember($this->owner, $this->organization);

    // O provedor manda o comprador de volta com os parâmetros dele na query string —
    // todos forjáveis, todos ignorados.
    $this->get(route('billing.return', ['outcome' => 'success']).'?payment_id=999&status=approved&collection_status=approved&external_reference='.$payment->external_reference)
        ->assertRedirect(route('billing.index'))
        ->assertSessionHas('checkout_return', 'success');

    $payment->refresh();
    $subscription->refresh();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->paid_at)->toBeNull()
        ->and($payment->activated_at)->toBeNull()
        ->and($payment->provider_payment_id)->toBeNull()
        ->and($subscription->plan_id)->toBe(Plan::free()?->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active);
});

test('a página de cobrança mostra o retorno como "estamos confirmando", sem afirmar que o plano mudou', function () {
    subscribeOrganization($this->organization, Plan::free() ?? Plan::factory()->free()->create());

    actingAsMember($this->owner, $this->organization);

    $response = $this->withSession(['checkout_return' => 'success'])->get(route('billing.index'));

    $page = $response->viewData('page');

    expect($page['props']['checkout_return'])->toBe('success')
        ->and($page['props']['subscription']['plan']['code'])->toBe(Plan::CODE_FREE);
});

test('o retorno aceita apenas success, failure e pending', function () {
    actingAsMember($this->owner, $this->organization);

    $this->get(route('billing.index').'/../plano/retorno/qualquercoisa')->assertNotFound();
});
