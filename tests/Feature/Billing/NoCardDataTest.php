<?php

use App\Jobs\Billing\SyncMercadoPagoPayment;
use App\Models\Payment;
use App\Models\PaymentWebhookReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/BillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Nenhum dado de cartão em lugar nenhum
|--------------------------------------------------------------------------
| No Checkout Pro o pagamento acontece inteiramente no ambiente do provedor: ele
| não nos devolve número de cartão, validade nem código de segurança, e não há
| cartão salvo (RECONCILIACAO Q21). Este arquivo prova que também não guardamos
| nada disso por acidente — nem em coluna, nem em JSON, nem em log.
*/

/** Números que servem de isca: se aparecerem em algum lugar, o teste falha. */
const FAKE_PAN = '4235647728025682';

const FAKE_CVV = '123';

beforeEach(function (): void {
    $this->secret = billingSecret();
    $this->gateway = useFakeGateway();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->owner = $owner;
    $this->plan = paidPlan();
    $this->subscription = subscribeOrganization($organization, $this->plan);
});

test('nenhuma coluna de pagamento guarda número de cartão ou código de segurança', function () {
    $payment = pendingPaymentFor($this->organization, $this->plan, $this->subscription);

    $this->gateway->pretendPayment('1234567890', $payment->external_reference, (int) $this->plan->price_cents, paymentMethodId: 'visa');

    postMercadoPagoWebhook('1234567890', $this->secret)->assertOk();

    $row = DB::table('payments')->where('id', $payment->id)->first();

    expect($row)->not->toBeNull();

    $dump = json_encode((array) $row, JSON_UNESCAPED_UNICODE);

    expect($dump)->not->toContain(FAKE_PAN)
        // Nenhuma coluna cujo nome sugira dado de cartão.
        ->and(array_keys((array) $row))->not->toContain('card_number')
        ->and(array_keys((array) $row))->not->toContain('card_last_four')
        ->and(array_keys((array) $row))->not->toContain('security_code');

    // O que fica é apenas a bandeira/meio e o e-mail mascarado.
    $payment->refresh();

    expect($payment->payment_method_id)->toBe('visa')
        ->and($payment->payer_email_masked)->toContain('*')
        ->and($payment->payer_email_masked)->not->toContain('comprador@');
});

test('o recibo do webhook não guarda dado sensível do payload', function () {
    $payment = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $this->gateway->pretendPayment('5555555555', $payment->external_reference, (int) $this->plan->price_cents);

    // O aviso chega com bagagem extra (o provedor não manda isso, mas se mandasse...).
    $url = route('webhooks.mercadopago').'?data.id=5555555555&type=payment';
    $requestId = 'req-com-bagagem';

    $this->postJson($url, [
        'id' => 1,
        'type' => 'payment',
        'action' => 'payment.updated',
        'data' => ['id' => '5555555555'],
        'card' => ['number' => FAKE_PAN, 'security_code' => FAKE_CVV],
        'payer' => ['email' => 'comprador@exemplo.com'],
    ], [
        'x-signature' => mercadoPagoSignatureHeader('5555555555', $requestId, $this->secret),
        'x-request-id' => $requestId,
    ])->assertOk();

    $receipt = PaymentWebhookReceipt::query()->firstOrFail();
    $stored = json_encode($receipt->payload, JSON_UNESCAPED_UNICODE);

    expect($stored)->not->toContain(FAKE_PAN)
        ->and($stored)->not->toContain('security_code')
        ->and($stored)->not->toContain('comprador@exemplo.com')
        ->and($receipt->payload)->toHaveKeys(['id', 'type', 'action', 'data']);
});

test('a chave secreta do webhook não é gravada em lugar nenhum do recibo', function () {
    $payment = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $this->gateway->pretendPayment('6666666666', $payment->external_reference, (int) $this->plan->price_cents);

    postMercadoPagoWebhook('6666666666', $this->secret)->assertOk();

    $row = DB::table('payment_webhook_receipts')->first();
    $dump = json_encode((array) $row, JSON_UNESCAPED_UNICODE);

    // O `signature_header` guardado é o MAC (ts + v1), nunca a chave que o gerou.
    expect($dump)->not->toContain($this->secret)
        ->and($row->signature_header)->toStartWith('ts=');
});

test('nenhum log de cobrança carrega credencial, cabeçalho de assinatura ou e-mail completo', function () {
    $captured = [];

    Log::listen(function ($message) use (&$captured): void {
        $captured[] = $message->message.' '.json_encode($message->context, JSON_UNESCAPED_UNICODE);
    });

    $payment = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $this->gateway->pretendPayment('7777777777', $payment->external_reference, (int) $this->plan->price_cents);

    postMercadoPagoWebhook('7777777777', $this->secret)->assertOk();
    postMercadoPagoWebhook('7777777777', 'segredo-errado')->assertStatus(401);

    $all = implode("\n", $captured);

    expect($all)->not->toBeEmpty()
        ->and($all)->not->toContain($this->secret)
        ->and($all)->not->toContain('x-signature')
        ->and($all)->not->toContain('ts=')
        ->and($all)->not->toContain(FAKE_PAN);
});

test('o payload do job de sincronização carrega apenas identificadores', function () {
    $job = new SyncMercadoPagoPayment('1234567890', 42);

    $serialized = serialize($job);

    expect($serialized)->toContain('1234567890')
        ->and($serialized)->not->toContain($this->secret)
        ->and($serialized)->not->toContain('APP_USR');
});

test('a tela de cobrança nunca expõe últimos dígitos de cartão', function () {
    $this->withoutVite();

    $payment = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $this->gateway->pretendPayment('8888888888', $payment->external_reference, (int) $this->plan->price_cents, paymentMethodId: 'master');
    postMercadoPagoWebhook('8888888888', $this->secret)->assertOk();

    actingAsMember($this->owner, $this->organization);

    $props = $this->get(route('billing.index'))->viewData('page')['props'];

    expect($props['payment_method'])->not->toBeNull()
        ->and($props['payment_method']['last_four'])->toBeNull()
        ->and($props['payment_method']['type'])->toBe('credit_card')
        ->and($props['payment_method']['label'])->toBe('Cartão de crédito (Mastercard)');
});

test('valores trafegam em centavos inteiros com moeda explícita', function () {
    $payment = pendingPaymentFor($this->organization, $this->plan, $this->subscription);

    expect($payment->amount_cents)->toBeInt()
        ->and($payment->amount_cents)->toBe(4_900)
        ->and($payment->currency)->toBe('BRL');

    $row = DB::table('payments')->where('id', $payment->id)->first();

    expect($row->amount_cents)->toBe(4_900)
        ->and($row->currency)->toBe('BRL');

    expect(Payment::formatBrl(4_900))->toBe('R$ 49,00');
});
