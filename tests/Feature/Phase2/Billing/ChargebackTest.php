<?php

use App\Enums\EnvelopeStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WebhookProcessingStatus;
use App\Integrations\Payments\Dto\GatewayChargeback;
use App\Integrations\Payments\Dto\GatewayMerchantOrder;
use App\Models\Envelope;
use App\Models\PaymentChargeback;
use App\Models\PaymentWebhookReceipt;
use App\Services\Billing\BillingAlerts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Contestação (chargeback) e ordem comercial pelo webhook
|--------------------------------------------------------------------------
| O aviso continua exigindo assinatura válida e é só gatilho: quem decide são as consultas
| GET /v1/chargebacks/{id} e GET /v1/payments/{id}. Documentos nunca são desfeitos.
*/

beforeEach(function (): void {
    ['gateway' => $this->gateway, 'organization' => $this->organization, 'owner' => $this->owner,
        'plan' => $this->plan, 'subscription' => $this->subscription, 'payment' => $this->payment,
        'secret' => $this->secret] = extendedBillingContext();
});

test('webhook de contestação sem assinatura válida: 401 e nada acontece', function () {
    postMercadoPagoWebhook('123456', 'chave-errada', action: 'chargebacks.created', type: 'chargebacks')
        ->assertUnauthorized();

    postMercadoPagoWebhook('123456', null, action: 'chargebacks.created', type: 'chargebacks')
        ->assertUnauthorized();

    expect(PaymentWebhookReceipt::query()->count())->toBe(0)
        ->and(PaymentChargeback::withoutOrganizationScope()->count())->toBe(0)
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Approved);
});

test('contestação: consulta o provedor, marca pagamento e plano, alerta a equipe e não toca documentos', function () {
    Log::spy();

    $envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->completed()->create();
    $completedAt = $envelope->completed_at?->toIso8601String();

    $this->gateway->pretendChargeback(new GatewayChargeback(
        chargebackId: '123456',
        providerPaymentIds: ['9000000001'],
        amountCents: 4_900,
        currency: 'BRL',
        reason: 'fraud',
        coverageApplied: null,
        documentationStatus: 'pending',
        documentationDeadline: new DateTimeImmutable('+10 days'),
    ));
    $this->gateway->pretendPayment('9000000001', $this->payment->external_reference, 4_900, 'charged_back', 'in_process');

    postMercadoPagoWebhook('123456', $this->secret, action: 'chargebacks.created', type: 'chargebacks')
        ->assertOk()
        ->assertJson(['received' => true]);

    expect($this->payment->fresh()->status)->toBe(PaymentStatus::ChargedBack);

    $chargeback = PaymentChargeback::withoutOrganizationScope()->sole();
    expect($chargeback->provider_chargeback_id)->toBe('123456')
        ->and($chargeback->payment_id)->toBe($this->payment->id)
        ->and($chargeback->amount_cents)->toBe(4_900)
        ->and($chargeback->currency)->toBe('BRL')
        ->and($chargeback->reason)->toBe('fraud')
        ->and($chargeback->documentation_status)->toBe('pending')
        ->and($chargeback->outcomeLabel())->toBe('Em disputa');

    // "charged_back suspende envio como past_due" (roadmap §2.20).
    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and(PaymentWebhookReceipt::query()->sole()->processing_status)->toBe(WebhookProcessingStatus::Processed);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => $message === 'billing.alert'
        && ($context['alert'] ?? null) === 'billing_chargeback'
        && ($context['sending_suspended'] ?? null) === true
        && ($context['payment'] ?? null) === $this->payment->ulid)->once();

    // O documento concluído continua exatamente como estava.
    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->completed_at?->toIso8601String())->toBe($completedAt);
});

test('reentrega da mesma contestação não duplica a linha nem o alerta', function () {
    $this->gateway->pretendChargeback(new GatewayChargeback('123457', ['9000000001'], 4_900, 'BRL'));
    $this->gateway->pretendPayment('9000000001', $this->payment->external_reference, 4_900, 'charged_back', 'in_process');

    Log::spy();
    postMercadoPagoWebhook('123457', $this->secret, action: 'chargebacks.created', type: 'chargebacks')->assertOk();
    postMercadoPagoWebhook('123457', $this->secret, action: 'chargebacks.updated', type: 'chargebacks')->assertOk();

    expect(PaymentChargeback::withoutOrganizationScope()->count())->toBe(1);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => $message === 'billing.alert'
        && ($context['alert'] ?? null) === 'billing_chargeback')->once();
});

test('contestação que o provedor não reconhece não altera nada', function () {
    postMercadoPagoWebhook('999', $this->secret, action: 'chargebacks.created', type: 'chargebacks')->assertOk();

    expect(PaymentWebhookReceipt::query()->sole()->processing_status)->toBe(WebhookProcessingStatus::Failed)
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Approved)
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('com a flag, um charged_back pelo tópico payment também suspende o envio', function () {
    $this->gateway->pretendPayment('9000000001', $this->payment->external_reference, 4_900, 'charged_back', 'in_process');

    postMercadoPagoWebhook('9000000001', $this->secret)->assertOk();

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

test('contestação de um ciclo antigo não mexe no plano vigente, mas alerta', function () {
    Log::spy();
    $newer = activatedPaymentFor($this->organization, $this->plan, $this->subscription, $this->gateway, '9000000003', paidDaysAgo: 0);
    $newer->forceFill(['activated_at' => now()])->save();

    $this->gateway->pretendPayment('9000000001', $this->payment->external_reference, 4_900, 'charged_back', 'in_process');
    postMercadoPagoWebhook('9000000001', $this->secret)->assertOk();

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => ($context['alert'] ?? null) === 'billing_chargeback'
        && ($context['sending_suspended'] ?? null) === false)->once();
});

test('ordem comercial: cada pagamento nosso é sincronizado pela consulta', function () {
    $pending = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $this->gateway->pretendPayment('4440001', $pending->external_reference, 4_900);
    $this->gateway->pretendMerchantOrder(new GatewayMerchantOrder(
        merchantOrderId: '55501',
        preferenceId: 'pref-1',
        externalReference: $pending->external_reference,
        status: 'closed',
        orderStatus: 'paid',
        totalAmountCents: 4_900,
        paidAmountCents: 4_900,
        payments: [['id' => '4440001', 'status' => 'approved', 'status_detail' => 'accredited', 'amount_cents' => 4_900]],
    ));

    postMercadoPagoWebhook('55501', $this->secret, action: 'merchant_order.updated', type: 'merchant_order')->assertOk();

    $pending->refresh();
    expect($pending->status)->toBe(PaymentStatus::Approved)
        ->and($pending->activated_at)->not->toBeNull();
});

test('o alerta vai por e-mail quando configurado, com contexto mínimo', function () {
    config()->set('assinavelox.billing.alert_email', 'financeiro@operadora.test');

    Mail::shouldReceive('raw')->once()->withArgs(function (string $text, Closure $callback): bool {
        return str_contains($text, 'Contestação')
            && str_contains($text, 'amount_cents: 4900')
            && ! str_contains($text, '@exemplo.com');
    });

    app(BillingAlerts::class)->raise('billing_chargeback', 'Contestação (chargeback) registrada pelo Mercado Pago.', [
        'payment' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        'amount_cents' => 4_900,
        'currency' => 'BRL',
    ]);
});
