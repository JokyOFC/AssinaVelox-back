<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\PaymentWebhookReceipt;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/BillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Idempotência e ordem dos eventos
|--------------------------------------------------------------------------
| O provedor repete a notificação até receber 200 e não garante ordem. Os dois
| riscos concretos: ativar duas vezes o mesmo pagamento e rebaixar um pagamento
| já aprovado com um evento antigo. Nenhum dos dois pode acontecer.
*/

beforeEach(function (): void {
    $this->secret = billingSecret();
    $this->gateway = useFakeGateway();

    ['organization' => $organization] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->plan = paidPlan();
    $this->subscription = subscribeOrganization($organization, $this->plan, used: 4);
    $this->payment = pendingPaymentFor($organization, $this->plan, $this->subscription);

    $this->providerPaymentId = '1234567890';
    $this->gateway->pretendPayment(
        $this->providerPaymentId,
        $this->payment->external_reference,
        (int) $this->plan->price_cents,
    );
});

test('o mesmo evento entregue duas vezes não ativa o plano duas vezes', function () {
    postMercadoPagoWebhook($this->providerPaymentId, $this->secret)->assertOk();

    $this->subscription->refresh();
    $firstEnd = $this->subscription->current_period_end;
    $this->payment->refresh();
    $firstActivation = $this->payment->activated_at;

    expect($this->payment->status)->toBe(PaymentStatus::Approved)
        ->and($firstActivation)->not->toBeNull()
        ->and($this->subscription->envelopes_used)->toBe(0); // o ciclo foi zerado

    // Reentrega idêntica (mesmo type, mesmo data.id, mesma action).
    postMercadoPagoWebhook($this->providerPaymentId, $this->secret)
        ->assertOk()
        ->assertJson(['duplicate' => true]);

    $this->subscription->refresh();
    $this->payment->refresh();

    expect(PaymentWebhookReceipt::query()->count())->toBe(1)
        ->and($this->payment->activated_at?->toIso8601String())->toBe($firstActivation?->toIso8601String())
        ->and($this->subscription->current_period_end?->toIso8601String())->toBe($firstEnd?->toIso8601String());
});

test('payment.created e payment.updated do mesmo pagamento ativam uma única vez', function () {
    // Fingerprints diferentes (a `action` faz parte), então ambos são processados — e a
    // ativação continua sendo uma só, porque `payments.activated_at` é a marca.
    postMercadoPagoWebhook($this->providerPaymentId, $this->secret, action: 'payment.created')->assertOk();

    $this->subscription->refresh();
    $end = $this->subscription->current_period_end;

    postMercadoPagoWebhook($this->providerPaymentId, $this->secret, action: 'payment.updated')->assertOk();

    $this->subscription->refresh();

    expect(PaymentWebhookReceipt::query()->count())->toBe(2)
        ->and($this->subscription->current_period_end?->toIso8601String())->toBe($end?->toIso8601String());
});

test('um evento antigo não rebaixa um pagamento já aprovado', function () {
    postMercadoPagoWebhook($this->providerPaymentId, $this->secret, action: 'payment.updated')->assertOk();

    $this->payment->refresh();
    $this->subscription->refresh();

    expect($this->payment->status)->toBe(PaymentStatus::Approved);

    $activatedAt = $this->payment->activated_at;
    $periodEnd = $this->subscription->current_period_end;

    // A retentativa atrasada da PRIMEIRA notificação chega agora, ainda dizendo
    // "pending" na consulta ao provedor.
    $this->gateway->pretendPayment(
        $this->providerPaymentId,
        $this->payment->external_reference,
        (int) $this->plan->price_cents,
        status: 'pending',
        statusDetail: 'pending_waiting_payment',
    );

    postMercadoPagoWebhook($this->providerPaymentId, $this->secret, action: 'payment.created')->assertOk();

    $this->payment->refresh();
    $this->subscription->refresh();

    expect($this->payment->status)->toBe(PaymentStatus::Approved)
        ->and($this->payment->activated_at?->toIso8601String())->toBe($activatedAt?->toIso8601String())
        ->and($this->subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($this->subscription->current_period_end?->toIso8601String())->toBe($periodEnd?->toIso8601String());

    $receipt = PaymentWebhookReceipt::query()->where('action', 'payment.created')->firstOrFail();

    expect($receipt->processing_status)->toBe(WebhookProcessingStatus::Ignored)
        ->and($receipt->error)->toBe('out_of_order');
});

test('um estorno posterior à aprovação é gravado (é transição legítima, não evento fora de ordem)', function () {
    postMercadoPagoWebhook($this->providerPaymentId, $this->secret, action: 'payment.updated')->assertOk();

    $this->gateway->pretendPayment(
        $this->providerPaymentId,
        $this->payment->external_reference,
        (int) $this->plan->price_cents,
        status: 'refunded',
        statusDetail: 'refunded',
    );

    postMercadoPagoWebhook($this->providerPaymentId, $this->secret, action: 'payment.refunded')->assertOk();

    $this->payment->refresh();

    expect($this->payment->status)->toBe(PaymentStatus::Refunded)
        // A ativação já feita continua registrada: ela não é desfeita por aqui.
        ->and($this->payment->activated_at)->not->toBeNull();
});

test('valor divergente do que pedimos não ativa nada e o recibo fica como falha', function () {
    $this->gateway->pretendPayment(
        $this->providerPaymentId,
        $this->payment->external_reference,
        amountCents: 100, // R$ 1,00 em vez de R$ 49,00
    );

    postMercadoPagoWebhook($this->providerPaymentId, $this->secret)->assertOk();

    $this->payment->refresh();

    expect($this->payment->status)->toBe(PaymentStatus::Pending)
        ->and($this->payment->activated_at)->toBeNull();

    expect(PaymentWebhookReceipt::query()->firstOrFail()->processing_status)
        ->toBe(WebhookProcessingStatus::Failed);
});

test('pagamento de produção chegando num pagamento de sandbox é ignorado', function () {
    $this->gateway->pretendPayment(
        $this->providerPaymentId,
        $this->payment->external_reference,
        (int) $this->plan->price_cents,
        liveMode: true,
    );

    postMercadoPagoWebhook($this->providerPaymentId, $this->secret)->assertOk();

    $this->payment->refresh();

    expect($this->payment->activated_at)->toBeNull();
    expect(PaymentWebhookReceipt::query()->firstOrFail()->error)->toBe('environment_mismatch');
});

test('evento de tópico que não tratamos é reconhecido, marcado como ignorado e não vira job', function () {
    Queue::fake();

    postMercadoPagoWebhook('55667788', $this->secret, action: 'created', type: 'merchant_order')
        ->assertOk()
        ->assertJson(['ignored' => 'topic_not_handled']);

    Queue::assertNothingPushed();

    expect(PaymentWebhookReceipt::query()->firstOrFail()->processing_status)
        ->toBe(WebhookProcessingStatus::Ignored);
});

test('pagamento que não é nosso é ignorado sem tocar em nada', function () {
    $this->gateway->pretendPayment('4242424242', 'referencia-de-outra-instalacao', 4_900);

    postMercadoPagoWebhook('4242424242', $this->secret)->assertOk();

    $this->payment->refresh();

    expect($this->payment->activated_at)->toBeNull();
    expect(PaymentWebhookReceipt::query()->firstOrFail()->error)->toBe('payment_not_found');
});
