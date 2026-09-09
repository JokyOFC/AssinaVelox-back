<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\Billing\SyncMercadoPagoPayment;
use App\Models\PaymentWebhookReceipt;
use App\Models\Subscription;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/BillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Autenticação do webhook
|--------------------------------------------------------------------------
| A regra é uma só: sem assinatura válida, nada acontece. Nenhum job é
| despachado, nenhum recibo é gravado, nenhum plano é ativado — e a resposta é
| 401, com corpo genérico (o motivo da recusa fica no log, não na resposta).
*/

beforeEach(function (): void {
    Queue::fake();
    $this->secret = billingSecret();
    useFakeGateway();

    ['organization' => $organization] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->plan = paidPlan();
    $this->subscription = subscribeOrganization($organization, $this->plan);
    $this->payment = pendingPaymentFor($organization, $this->plan, $this->subscription);
});

test('assinatura válida é aceita, grava o recibo e despacha a consulta ao provedor', function () {
    postMercadoPagoWebhook('9911223344', $this->secret)
        ->assertOk()
        ->assertJson(['received' => true]);

    $receipt = PaymentWebhookReceipt::query()->firstOrFail();

    expect($receipt->signature_valid)->toBeTrue()
        ->and($receipt->event_fingerprint)->toBe('payment:9911223344:payment.updated')
        ->and($receipt->provider)->toBe('mercadopago');

    Queue::assertPushed(SyncMercadoPagoPayment::class, fn (SyncMercadoPagoPayment $job): bool => $job->providerPaymentId === '9911223344');
});

test('assinatura inválida devolve 401 e não ativa nada', function () {
    postMercadoPagoWebhook('9911223344', 'segredo-errado-do-atacante')
        ->assertStatus(401)
        ->assertExactJson(['error' => 'invalid_signature']);

    expect(PaymentWebhookReceipt::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    $this->payment->refresh();
    $this->subscription->refresh();

    expect($this->payment->status)->toBe(PaymentStatus::Pending)
        ->and($this->payment->activated_at)->toBeNull()
        ->and($this->subscription->current_period_end?->toIso8601String())
        ->toBe($this->subscription->current_period_end?->toIso8601String());
});

test('assinatura ausente devolve 401', function () {
    postMercadoPagoWebhook('9911223344', null)
        ->assertStatus(401);

    expect(PaymentWebhookReceipt::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('cabeçalho x-request-id ausente invalida a assinatura (o manifesto muda)', function () {
    postMercadoPagoWebhook('9911223344', $this->secret, headers: ['x-request-id' => null])
        ->assertStatus(401);

    Queue::assertNothingPushed();
});

test('carimbo de tempo fora da janela devolve 401 mesmo com o HMAC correto', function () {
    // Assinatura correta, mas com `ts` de duas horas atrás: fora dos 300 s de tolerância.
    $stale = (int) round(now()->subHours(2)->getTimestamp() * 1000);

    postMercadoPagoWebhook('9911223344', $this->secret, timestampMs: $stale)
        ->assertStatus(401);

    expect(PaymentWebhookReceipt::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('carimbo em milissegundos dentro da janela é aceito (o formato que o provedor envia)', function () {
    $recent = (int) round(now()->subSeconds(30)->getTimestamp() * 1000);

    postMercadoPagoWebhook('9911223344', $this->secret, timestampMs: $recent)->assertOk();

    Queue::assertPushed(SyncMercadoPagoPayment::class);
});

test('sem chave secreta configurada o webhook recusa tudo com 401', function () {
    config()->set('assinavelox.mercadopago.webhook_secret', null);

    postMercadoPagoWebhook('9911223344', $this->secret)->assertStatus(401);

    expect(PaymentWebhookReceipt::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    expect(Subscription::withoutOrganizationScope()->where('organization_id', $this->organization->id)->first()?->status)
        ->toBe(SubscriptionStatus::Active);
});

test('o webhook não exige CSRF nem sessão autenticada', function () {
    // Sem actingAs e sem token: a rota está na exceção de CSRF de bootstrap/app.php.
    postMercadoPagoWebhook('7788990011', $this->secret)->assertOk();
});
