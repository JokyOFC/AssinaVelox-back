<?php

use App\Integrations\Dto\CheckoutPreferenceRequest;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Integrations\Payments\MercadoPagoGateway;
use App\Models\PaymentMethodCheck;
use App\Services\Billing\ReconcilePayments;
use App\Services\Billing\RefreshPaymentMethods;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Contrato do adaptador real com os endpoints documentados — sem rede (Http::fake)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
    enableExtendedPayments();
    config()->set('assinavelox.mercadopago.access_token', 'APP_USR-token');
    config()->set('assinavelox.mercadopago.retry_delay_ms', 0);
    $this->gateway = fn (): MercadoPagoGateway => app(MercadoPagoGateway::class);
});

test('estorno integral vai sem amount; parcial em unidades; sempre com X-Idempotency-Key', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/123/refunds' => Http::response(['id' => 555, 'payment_id' => 123, 'amount' => 49.0, 'status' => 'approved'], 201),
    ]);

    $refund = ($this->gateway)()->refundPayment('123', null, 'chave-1');
    ($this->gateway)()->refundPayment('123', 1_050, 'chave-2');

    expect($refund->refundId)->toBe('555')
        ->and($refund->amountCents)->toBe(4_900)
        ->and($refund->status)->toBe('approved');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.mercadopago.com/v1/payments/123/refunds'
        && $request->hasHeader('X-Idempotency-Key', 'chave-1')
        && $request->hasHeader('Authorization', 'Bearer APP_USR-token')
        && $request->body() === '{}');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Idempotency-Key', 'chave-2')
        && $request->data() === ['amount' => 10.5]);
});

test('timeout no estorno: repete só com a mesma chave e termina inconclusivo, nunca sucesso', function () {
    config()->set('assinavelox.mercadopago.retries', 2);

    // Requisições que terminam em exceção não entram no registro do Http::fake: contamos aqui.
    $keys = [];
    Http::fake(function (Request $request) use (&$keys) {
        $keys[] = $request->header('X-Idempotency-Key')[0] ?? null;

        throw new ConnectionException('timeout');
    });

    expect(fn () => ($this->gateway)()->refundPayment('123', null, 'chave-estavel'))
        ->toThrow(fn (PaymentGatewayException $exception) => expect($exception->inconclusive)->toBeTrue());

    // Três tentativas (1 + 2 repetições), todas com a MESMA chave.
    expect($keys)->toBe(['chave-estavel', 'chave-estavel', 'chave-estavel']);
});

test('lista de estornos é lida do endpoint documentado', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/123/refunds' => Http::response([
            ['id' => 1, 'payment_id' => 123, 'amount' => 10, 'status' => 'approved'],
            ['id' => 2, 'payment_id' => 123, 'amount' => 5.5, 'status' => 'in_process'],
        ]),
    ]);

    $refunds = ($this->gateway)()->listRefunds('123');

    expect($refunds)->toHaveCount(2)
        ->and($refunds[1]->amountCents)->toBe(550)
        ->and($refunds[1]->status)->toBe('in_process');
});

test('cancelamento: PUT com status=cancelled e chave de idempotência', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/777' => Http::response([
            'id' => 777, 'status' => 'cancelled', 'status_detail' => 'by_collector', 'currency_id' => 'BRL',
            'transaction_amount' => 49, 'external_reference' => 'REF', 'payment_method_id' => 'bolbradesco', 'live_mode' => false,
        ]),
    ]);

    $payment = ($this->gateway)()->cancelPayment('777', 'cancel-REF');

    expect($payment->status)->toBe('cancelled')->and($payment->amountCents)->toBe(4_900);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->data() === ['status' => 'cancelled']
        && $request->hasHeader('X-Idempotency-Key', 'cancel-REF'));
});

test('contestação: X-Caller-Id só quando configurado; pagamentos lidos como lista', function () {
    Http::fake([
        'api.mercadopago.com/v1/chargebacks/*' => Http::response([
            'id' => 'CB1', 'payments' => [123, ['id' => 456]], 'currency' => 'BRL', 'amount' => 49.9,
            'reason' => 'fraud', 'coverage_applied' => false, 'documentation_status' => 'review_pending',
            'date_documentation_deadline' => '2026-09-30T00:00:00.000-04:00', 'live_mode' => false,
        ]),
    ]);

    $chargeback = ($this->gateway)()->getChargeback('CB1');

    expect($chargeback->providerPaymentIds)->toBe(['123', '456'])
        ->and($chargeback->amountCents)->toBe(4_990)
        ->and($chargeback->coverageApplied)->toBeFalse()
        ->and($chargeback->documentationStatus)->toBe('review_pending');
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Caller-Id'));

    config()->set('assinavelox.mercadopago.seller_user_id', '724484980');
    ($this->gateway)()->getChargeback('CB1');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Caller-Id', '724484980'));
});

test('busca de pagamentos: janela por date_last_updated, paginação por offset e página parcial', function () {
    config()->set('assinavelox.mercadopago.reconciliation.page_size', 2);
    config()->set('assinavelox.mercadopago.reconciliation.max_pages', 2);
    config()->set('assinavelox.mercadopago.driver', 'mercadopago');

    $item = fn (int $id): array => ['id' => $id, 'status' => 'approved', 'transaction_amount' => 1, 'currency_id' => 'BRL', 'external_reference' => 'X'.$id, 'live_mode' => false];

    Http::fake([
        'api.mercadopago.com/v1/payments/search*' => Http::sequence()
            ->push(['results' => [$item(1), $item(2)], 'paging' => ['total' => 5, 'limit' => 2, 'offset' => 0]])
            ->push(['results' => [$item(3), $item(4)], 'paging' => ['total' => 5, 'limit' => 2, 'offset' => 2]]),
    ]);

    $run = app(ReconcilePayments::class)->run('schedule');

    expect($run->status)->toBe('partial')->and($run->remote_count)->toBe(4);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://api.mercadopago.com/v1/payments/search')
            && $query['range'] === 'date_last_updated'
            && $query['sort'] === 'date_last_updated'
            && $query['criteria'] === 'asc'
            && $query['limit'] === '2'
            && $query['offset'] === '2'
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $query['begin_date']) === 1;
    });
});

test('meios de pagamento: lista documentada, só os campos necessários', function () {
    config()->set('assinavelox.mercadopago.driver', 'mercadopago');
    Http::fake([
        'api.mercadopago.com/v1/payment_methods' => Http::response([
            ['id' => 'pix', 'name' => 'Pix', 'payment_type_id' => 'bank_transfer', 'status' => 'active', 'secure_thumbnail' => 'https://x', 'min_allowed_amount' => 0.01],
        ]),
    ]);

    app(RefreshPaymentMethods::class)->handle();

    expect(PaymentMethodCheck::query()->sole()->methods)->toBe([
        ['id' => 'pix', 'name' => 'Pix', 'payment_type_id' => 'bank_transfer', 'status' => 'active'],
    ]);
});

test('preferência com a flag: exclusões da política e date_of_expiration; sem a flag, igual à Fase 1', function () {
    Http::fake([
        'api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'P1', 'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=P1', 'live_mode' => false]),
    ]);

    config()->set('assinavelox.mercadopago.enabled_methods', 'pix,card');
    config()->set('assinavelox.mercadopago.offline_expiration_hours', 96);

    $request = new CheckoutPreferenceRequest('REFX', 'Plano', 4_900, 'https://app.test/s', 'https://app.test/f', 'https://app.test/p');

    ($this->gateway)()->createCheckoutPreference($request);

    Http::assertSent(function (Request $sent): bool {
        $body = $sent->data();
        $expires = new DateTimeImmutable((string) ($body['date_of_expiration'] ?? 'now'));

        return $body['payment_methods']['excluded_payment_types'] === [['id' => 'ticket']]
            && abs($expires->getTimestamp() - (time() + 96 * 3600)) < 120;
    });

    enableExtendedPayments(false);
    app()->forgetInstance(MercadoPagoGateway::class);
    ($this->gateway)()->createCheckoutPreference(new CheckoutPreferenceRequest('REFY', 'Plano', 4_900, 'https://app.test/s', 'https://app.test/f', 'https://app.test/p'));

    Http::assertSent(fn (Request $sent): bool => $sent->data()['external_reference'] === 'REFY'
        && ! array_key_exists('date_of_expiration', $sent->data())
        && ! array_key_exists('excluded_payment_types', $sent->data()['payment_methods'] ?? []));
});
