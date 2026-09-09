<?php

use App\Enums\PaymentEnvironment;
use App\Integrations\Contracts\PaymentGateway;
use App\Integrations\Dto\CheckoutPreferenceRequest;
use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\Dto\GatewayMerchantOrder;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\MercadoPagoGateway;
use App\Jobs\Billing\SyncMercadoPagoPayment;
use App\Services\Billing\BillingSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/BillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Identificação do adaptador e configuração
|--------------------------------------------------------------------------
*/

test('sem credencial o adaptador real fica desabilitado e não chama endpoint nenhum', function () {
    config()->set('assinavelox.mercadopago.driver', 'mercadopago');
    config()->set('assinavelox.mercadopago.access_token', null);

    Http::fake();

    $gateway = app(CheckoutProGateway::class);

    expect($gateway)->toBeInstanceOf(MercadoPagoGateway::class)
        ->and($gateway->isConfigured())->toBeFalse();

    try {
        $gateway->getPayment('123');
        test()->fail('esperava PaymentGatewayException');
    } catch (PaymentGatewayException $exception) {
        expect($exception->errorCode)->toBe('gateway_not_configured')
            ->and($exception->getMessage())->toContain('desabilitado');
    }

    Http::assertNothingSent();
});

test('o dublê se identifica como falso em toda superfície', function () {
    $gateway = new FakePaymentGateway;

    expect($gateway->isFake())->toBeTrue()
        ->and($gateway->name())->toBe('fake')
        ->and($gateway->environment())->toBe(PaymentEnvironment::Sandbox)
        ->and(FakePaymentGateway::CHECKOUT_HOST)->toContain('falso')
        // Domínio reservado da RFC 2606: nunca resolve, então um link vazado falha em
        // vez de fingir um checkout.
        ->and(FakePaymentGateway::CHECKOUT_HOST)->toEndWith('.invalid');

    $preference = $gateway->createCheckoutPreference(new CheckoutPreferenceRequest(
        externalReference: '01HQZZZZZZZZZZZZZZZZZZZZZZ',
        title: 'Plano',
        amountCents: 4_900,
        successUrl: 'https://exemplo.test/s',
        failureUrl: 'https://exemplo.test/f',
        pendingUrl: 'https://exemplo.test/p',
    ));

    expect($preference->liveMode)->toBeFalse()
        ->and($preference->checkoutUrl)->toStartWith(FakePaymentGateway::CHECKOUT_HOST);
});

test('o adaptador real nunca se identifica como dublê', function () {
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token');

    $gateway = app(MercadoPagoGateway::class);

    expect($gateway->isFake())->toBeFalse()
        ->and($gateway->name())->toBe('mercadopago')
        ->and($gateway->isConfigured())->toBeTrue();
});

test('driver auto escolhe o real quando há credencial e o dublê quando não há', function () {
    config()->set('assinavelox.mercadopago.driver', 'auto');

    config()->set('assinavelox.mercadopago.access_token', null);
    app()->forgetInstance(CheckoutProGateway::class);
    expect(app(CheckoutProGateway::class))->toBeInstanceOf(FakePaymentGateway::class);

    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token');
    expect(app(CheckoutProGateway::class))->toBeInstanceOf(MercadoPagoGateway::class);
});

test('o contrato genérico PaymentGateway resolve para o mesmo adaptador', function () {
    config()->set('assinavelox.mercadopago.driver', 'fake');

    expect(app(PaymentGateway::class))->toBeInstanceOf(FakePaymentGateway::class);
});

test('sandbox e produção nunca se misturam: o ambiente vem da configuração', function () {
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token');

    config()->set('assinavelox.mercadopago.environment', 'production');
    expect(app(MercadoPagoGateway::class)->environment())->toBe(PaymentEnvironment::Production);

    config()->set('assinavelox.mercadopago.environment', 'sandbox');
    expect(app(MercadoPagoGateway::class)->environment())->toBe(PaymentEnvironment::Sandbox);

    // Valor inválido não vira produção por acidente.
    config()->set('assinavelox.mercadopago.environment', 'qualquer-coisa');
    expect(app(MercadoPagoGateway::class)->environment())->toBe(PaymentEnvironment::Sandbox);
});

/*
|--------------------------------------------------------------------------
| Chamadas HTTP do adaptador real
|--------------------------------------------------------------------------
*/

test('a preferência sai com valor em centavos convertido, moeda explícita e chave de idempotência estável', function () {
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token');
    config()->set('assinavelox.mercadopago.statement_descriptor', 'ASSINAVELOXDEMAIS'); // > 13 chars

    Http::fake([
        'api.mercadopago.com/checkout/preferences' => Http::response([
            'id' => '202809963-920c288b',
            'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=202809963-920c288b',
            'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=202809963-920c288b',
            'external_reference' => 'REF123',
            'live_mode' => false,
        ]),
    ]);

    $preference = app(MercadoPagoGateway::class)->createCheckoutPreference(
        new CheckoutPreferenceRequest(
            externalReference: 'REF123',
            title: 'Plano Profissional',
            amountCents: 4_900,
            successUrl: 'https://app.test/s',
            failureUrl: 'https://app.test/f',
            pendingUrl: 'https://app.test/p',
            notificationUrl: 'https://app.test/webhooks/mercadopago',
        ),
    );

    // Sempre o init_point — a documentação desaconselha o sandbox_init_point.
    expect($preference->checkoutUrl)->toBe('https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=202809963-920c288b')
        ->and($preference->preferenceId)->toBe('202809963-920c288b');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.mercadopago.com/checkout/preferences'
            && $request->hasHeader('Authorization', 'Bearer APP_USR-token')
            && $request->hasHeader('X-Idempotency-Key', 'pref-REF123')
            && $body['items'][0]['unit_price'] === 49.0
            && $body['items'][0]['currency_id'] === 'BRL'
            && $body['external_reference'] === 'REF123'
            && $body['auto_return'] === 'approved'
            && strlen($body['statement_descriptor']) <= 13;
    });
});

test('a consulta do pagamento devolve centavos inteiros e mascara o e-mail do pagador', function () {
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token');

    Http::fake([
        'api.mercadopago.com/v1/payments/*' => Http::response([
            'id' => 1234567890,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'currency_id' => 'BRL',
            'transaction_amount' => '24.50',
            'external_reference' => 'REF123',
            'payment_method_id' => 'visa',
            'payment_type_id' => 'credit_card',
            'live_mode' => false,
            'date_approved' => '2026-09-09T10:00:00.000-03:00',
            'payer' => ['email' => 'comprador@exemplo.com', 'identification' => ['type' => 'CPF', 'number' => '19119119100']],
        ]),
    ]);

    $payment = app(MercadoPagoGateway::class)->getPayment('1234567890');

    expect($payment->amountCents)->toBe(2_450)
        ->and($payment->currency)->toBe('BRL')
        ->and($payment->isApproved())->toBeTrue()
        ->and($payment->payerEmailMasked)->toBe('c********@exemplo.com')
        ->and($payment->approvedAt)->not->toBeNull()
        // O `raw` guardado não carrega o bloco do pagador nem nada de cartão.
        ->and($payment->raw)->not->toHaveKey('payer')
        ->and(json_encode($payment->raw))->not->toContain('19119119100');
});

test('a ordem comercial soma os pagamentos aprovados em centavos', function () {
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token');

    Http::fake([
        'api.mercadopago.com/merchant_orders/*' => Http::response([
            'id' => 9999999999,
            'status' => 'closed',
            'order_status' => 'paid',
            'preference_id' => 'pref-1',
            'external_reference' => 'REF123',
            'total_amount' => 49,
            'paid_amount' => 49,
            'payments' => [
                ['id' => 1, 'status' => 'cancelled', 'status_detail' => 'expired', 'transaction_amount' => 49, 'currency_id' => 'BRL'],
                ['id' => 2, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 49, 'currency_id' => 'BRL'],
            ],
        ]),
    ]);

    $order = app(MercadoPagoGateway::class)->getMerchantOrder('9999999999');

    expect($order->totalAmountCents)->toBe(4_900)
        ->and($order->approvedAmountCents())->toBe(4_900)
        ->and($order->isFullyPaid())->toBeTrue()
        ->and($order->currency)->toBe('BRL');
});

test('erro 4xx do provedor é recusa definitiva, não resposta inconclusiva', function () {
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token');
    config()->set('assinavelox.mercadopago.retries', 0);

    Http::fake(['api.mercadopago.com/*' => Http::response(['error' => 'bad_request', 'status' => 400], 400)]);

    try {
        app(MercadoPagoGateway::class)->getPayment('1');
        test()->fail('esperava PaymentGatewayException');
    } catch (PaymentGatewayException $exception) {
        expect($exception->inconclusive)->toBeFalse()
            ->and($exception->errorCode)->toBe('rejected')
            ->and($exception->status)->toBe(400);
    }
});

test('5xx persistente vira resposta inconclusiva, e a repetição usa a mesma chave de idempotência', function () {
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token');
    config()->set('assinavelox.mercadopago.retries', 2);
    config()->set('assinavelox.mercadopago.retry_delay_ms', 0);

    Http::fake(['api.mercadopago.com/*' => Http::response(['error' => 'internal'], 500)]);

    try {
        app(MercadoPagoGateway::class)->createCheckoutPreference(
            new CheckoutPreferenceRequest(
                externalReference: 'REF999',
                title: 'Plano',
                amountCents: 1_000,
                successUrl: 'https://app.test/s',
                failureUrl: 'https://app.test/f',
                pendingUrl: 'https://app.test/p',
            ),
        );
        test()->fail('esperava PaymentGatewayException');
    } catch (PaymentGatewayException $exception) {
        expect($exception->inconclusive)->toBeTrue();
    }

    // 1 tentativa + 2 repetições, todas com a mesma chave: o provedor nunca cria duas.
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Idempotency-Key', 'pref-REF999'));
});

test('a chave secreta e o access token nunca aparecem nas mensagens de erro', function () {
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token-supersecreto');
    config()->set('assinavelox.mercadopago.retries', 0);

    Http::fake(['api.mercadopago.com/*' => Http::response(['error' => 'forbidden'], 403)]);

    try {
        app(MercadoPagoGateway::class)->getPayment('1');
    } catch (PaymentGatewayException $exception) {
        expect($exception->getMessage())->not->toContain('supersecreto');
    }
});

test('BillingSettings recusa excluir account_money e corta a descrição da fatura em 13 caracteres', function () {
    config()->set('assinavelox.mercadopago.excluded_payment_types', 'ticket, account_money , atm');
    config()->set('assinavelox.mercadopago.statement_descriptor', 'ASSINAVELOXMUITOLONGO');

    $settings = app(BillingSettings::class);

    expect($settings->excludedPaymentTypes())->toBe(['ticket', 'atm'])
        ->and($settings->statementDescriptor())->toHaveLength(13);
});

test('o dublê consulta ordem comercial apenas quando ela foi programada', function () {
    $gateway = new FakePaymentGateway;

    expect(fn () => $gateway->getMerchantOrder('123'))->toThrow(PaymentGatewayException::class);

    $gateway->pretendMerchantOrder(new GatewayMerchantOrder(
        merchantOrderId: '123',
        preferenceId: 'pref-1',
        externalReference: 'REF123',
        status: 'closed',
        orderStatus: 'paid',
        totalAmountCents: 4_900,
        paidAmountCents: 4_900,
        payments: [['id' => '1', 'status' => 'approved', 'status_detail' => 'accredited', 'amount_cents' => 4_900]],
    ));

    $order = $gateway->getMerchantOrder('123');

    expect($order->isFullyPaid())->toBeTrue()
        ->and($order->approvedAmountCents())->toBe(4_900);
});

test('o job de sincronização usa a fila de cobrança', function () {
    expect((new SyncMercadoPagoPayment('1'))->queue)
        ->toBe(config('assinavelox.queues.billing'));
});
