<?php

use App\Enums\PaymentStatus;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\ReconciliationItem;
use App\Models\ReconciliationRun;
use App\Models\User;
use App\Services\Billing\ReconcilePayments;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Conciliação diária — aponta divergência, nunca corrige
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    ['gateway' => $this->gateway, 'organization' => $this->organization, 'owner' => $this->owner,
        'plan' => $this->plan, 'subscription' => $this->subscription, 'payment' => $this->payment] = extendedBillingContext();
});

test('detecta aprovado no provedor e pendente aqui, sem corrigir o pagamento', function () {
    Log::spy();
    $pending = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $this->gateway->pretendPayment('5001', $pending->external_reference, 4_900, 'approved');

    $run = app(ReconcilePayments::class)->run('schedule');

    expect($run->status)->toBe(ReconciliationRun::STATUS_COMPLETED)
        ->and($run->remote_count)->toBe(2)
        ->and($run->matched_count)->toBe(2)
        ->and($run->divergence_count)->toBe(1);

    $item = ReconciliationItem::query()->sole();
    expect($item->divergence)->toBe(ReconciliationItem::STATUS_MISMATCH)
        ->and($item->payment_id)->toBe($pending->id)
        ->and($item->local_status)->toBe('pending')
        ->and($item->provider_status)->toBe('approved')
        ->and($item->note)->toContain('Aprovado no provedor');

    $pending->refresh();
    expect($pending->status)->toBe(PaymentStatus::Pending)
        ->and($pending->activated_at)->toBeNull();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => ($context['alert'] ?? null) === 'billing_reconciliation_divergence'
        && ($context['divergences'] ?? null) === 1)->once();
});

test('detecta divergência de valor e de moeda, em centavos e com a moeda de cada lado', function () {
    $this->gateway->pretendPayment('9000000001', $this->payment->external_reference, 5_900, 'approved', 'accredited', 'USD');

    app(ReconcilePayments::class)->run('schedule');

    $items = ReconciliationItem::query()->orderBy('id')->get();
    expect($items->pluck('divergence')->all())->toBe([ReconciliationItem::AMOUNT_MISMATCH, ReconciliationItem::CURRENCY_MISMATCH]);

    $amount = $items->first();
    expect($amount->local_amount_cents)->toBe(4_900)
        ->and($amount->provider_amount_cents)->toBe(5_900)
        ->and($amount->local_currency)->toBe('BRL')
        ->and($amount->provider_currency)->toBe('USD')
        ->and($this->payment->fresh()->amount_cents)->toBe(4_900);
});

test('pagamentos iguais não geram divergência nem alerta', function () {
    Log::spy();

    $run = app(ReconcilePayments::class)->run('schedule');

    expect($run->divergence_count)->toBe(0)
        ->and(ReconciliationItem::query()->count())->toBe(0);
    Log::shouldNotHaveReceived('error');
});

test('falha do provedor: a execução falha, alerta e nada é alterado', function () {
    Log::spy();
    $this->gateway->failNextWith(PaymentGatewayException::inconclusive('search_payments'));

    $run = app(ReconcilePayments::class)->run('schedule');

    expect($run->status)->toBe(ReconciliationRun::STATUS_FAILED)
        ->and($run->error)->toBe('inconclusive')
        ->and(ReconciliationItem::query()->count())->toBe(0);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => ($context['alert'] ?? null) === 'billing_reconciliation_failed')->once();
});

test('referência no formato do AssinaVelox sem registro vira divergência; referência alheia é ignorada', function () {
    $this->gateway->pretendPayment('6001', (string) Str::ulid(), 1_000);
    $this->gateway->pretendPayment('6002', 'PEDIDO-LOJA-42', 1_000);

    $run = app(ReconcilePayments::class)->run('schedule');

    expect($run->divergence_count)->toBe(1)
        ->and(ReconciliationItem::query()->sole()->divergence)->toBe(ReconciliationItem::MISSING_LOCAL);
});

test('pagamento de outro ambiente é ignorado', function () {
    $this->gateway->pretendPayment('9000000001', $this->payment->external_reference, 1, 'refunded', liveMode: true);

    expect(app(ReconcilePayments::class)->run('schedule')->divergence_count)->toBe(0);
});

test('rodar duas vezes não duplica a divergência ainda aberta', function () {
    $pending = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $this->gateway->pretendPayment('5002', $pending->external_reference, 4_900, 'approved');

    app(ReconcilePayments::class)->run('schedule');
    $second = app(ReconcilePayments::class)->run('schedule');

    expect($second->divergence_count)->toBe(1)
        ->and(ReconciliationItem::query()->count())->toBe(1);
});

test('o admin dispara a conciliação, vê a divergência e a revisão não altera o pagamento', function () {
    $admin = User::factory()->platformAdmin()->create();
    $pending = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $this->gateway->pretendPayment('5003', $pending->external_reference, 4_900, 'approved');

    adminBillingPost($admin, 'admin.billing.reconcile')->assertSessionHas('success');

    expect(ReconciliationRun::query()->sole()->trigger)->toBe(ReconciliationRun::TRIGGER_ADMIN);

    $this->withoutVite();
    $this->actingAs($admin)->get(route('admin.billing.index', ['tab' => 'divergences']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/billing')
            ->where('counters.open_divergences', 1)
            ->where('rows.data.0.divergence', 'status_mismatch')
            ->where('rows.data.0.provider_amount_cents', 4_900)
            ->where('reconciliation.divergence_count', 1));

    $item = ReconciliationItem::query()->sole();
    adminBillingPost($admin, 'admin.billing.divergences.resolve', ['item' => $item->id], ['note' => 'Aviso perdido; reconsultado'])
        ->assertSessionHas('success');

    expect($item->fresh()->resolved_at)->not->toBeNull()
        ->and($item->fresh()->resolved_by_user_id)->toBe($admin->id)
        ->and($pending->fresh()->status)->toBe(PaymentStatus::Pending);
});
