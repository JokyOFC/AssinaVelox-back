<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\Billing\SyncMercadoPagoChargeback;
use App\Models\PaymentWebhookReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Flags `extended_payments` e `fiscal_invoices` desligadas (padrão): nada muda
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();

    ['gateway' => $this->gateway, 'organization' => $this->organization, 'owner' => $this->owner,
        'plan' => $this->plan, 'subscription' => $this->subscription, 'payment' => $this->payment,
        'secret' => $this->secret] = extendedBillingContext();

    // O contexto liga a flag; aqui o ponto é justamente o padrão desligado.
    enableExtendedPayments(false);
    enableFiscalInvoices(false);
});

test('as duas flags nascem desligadas na configuração', function () {
    expect(require base_path('config/assinavelox.php'))
        ->features->extended_payments->toBeFalse()
        ->features->fiscal_invoices->toBeFalse();
});

test('admin.billing.index continua o placeholder da Fase 1', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('admin.billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/placeholder')
            ->where('feature', 'admin_billing'));
});

test('as rotas novas respondem 404 e não chamam o provedor', function () {
    $admin = User::factory()->platformAdmin()->create();

    adminBillingPost($admin, 'admin.billing.reconcile')->assertNotFound();
    adminBillingPost($admin, 'admin.billing.payments.refund', ['payment' => $this->payment->ulid], [
        'reason' => 'Teste com a flag desligada', 'idempotency_key' => (string) Str::uuid(),
    ])->assertNotFound();

    $this->actingAs($this->owner)->post(route('billing.payments.cancel', $this->payment->ulid))->assertNotFound();
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('billing.payments.refund', $this->payment->ulid), ['reason' => 'Quero estornar', 'idempotency_key' => (string) Str::uuid()])
        ->assertNotFound();

    expect($this->gateway->refundRequests())->toBe([])
        ->and($this->gateway->cancelRequests())->toBe([]);
});

test('o tópico de contestação continua ignorado, sem job', function () {
    Queue::fake();

    postMercadoPagoWebhook('777', $this->secret, action: 'chargebacks.created', type: 'chargebacks')
        ->assertOk()
        ->assertJson(['ignored' => 'topic_not_handled']);

    Queue::assertNotPushed(SyncMercadoPagoChargeback::class);
    expect(PaymentWebhookReceipt::query()->sole()->error)->toBe('topic_not_handled');
});

test('um charged_back pelo tópico payment só é gravado no pagamento, como na Fase 1', function () {
    $this->gateway->pretendPayment('9000000001', $this->payment->external_reference, (int) $this->plan->price_cents, 'charged_back', 'in_process');

    postMercadoPagoWebhook('9000000001', $this->secret)->assertOk();

    expect($this->payment->fresh()->status)->toBe(PaymentStatus::ChargedBack)
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('a tela de cobrança não ganha props nem colunas novas', function () {
    $this->actingAs($this->owner)->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/billing')
            ->missing('extended')
            ->missing('fiscal_invoices')
            ->has('payments.data.0', fn (Assert $row) => $row
                ->missing('can_cancel')
                ->missing('can_request_refund')
                ->missing('fiscal')
                ->etc()));
});
