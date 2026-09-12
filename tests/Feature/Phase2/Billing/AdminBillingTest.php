<?php

use App\Models\Envelope;
use App\Models\Payment;
use App\Models\PaymentChargeback;
use App\Models\PaymentRefund;
use App\Models\ReconciliationItem;
use App\Models\ReconciliationRun;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Painel interno › Planos e faturamento — só platform admin, sem documentos
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();
    ['gateway' => $this->gateway, 'organization' => $this->organization, 'owner' => $this->owner,
        'plan' => $this->plan, 'subscription' => $this->subscription, 'payment' => $this->payment] = extendedBillingContext();
    $this->admin = User::factory()->platformAdmin()->create();
    $this->period = ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()];
});

test('só a equipe da plataforma acessa a página e as ações', function () {
    $this->actingAs($this->owner)->get(route('admin.billing.index'))->assertForbidden();
    $this->actingAs($this->owner)->post(route('admin.billing.reconcile'))->assertForbidden();
    $this->actingAs($this->owner)->post(route('admin.billing.methods.refresh'))->assertForbidden();

    auth()->logout();
    $this->get(route('admin.billing.index'))->assertRedirect(route('login'));

    $this->actingAs($this->admin)->get(route('admin.billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/billing'));

    expect(CurrentOrganization::instance()->has())->toBeFalse();
});

test('receita por moeda em centavos, estornos, contestações, inadimplência e divergências — sem nada de documentos', function () {
    Envelope::factory()->forOrganization($this->organization, $this->owner)->completed()->create(['title' => 'Contrato Sigiloso XPTO']);

    // Outra organização: pagamento em outra moeda e assinatura inadimplente.
    ['organization' => $other] = createOrganizationWithOwner(['name' => 'Beta Serviços']);
    $otherSubscription = subscribeOrganization($other, $this->plan, 'past_due');
    Payment::factory()->create([
        'organization_id' => $other->id, 'plan_id' => $this->plan->id, 'subscription_id' => $otherSubscription->id,
        'status' => 'approved', 'amount_cents' => 1_000, 'currency' => 'USD', 'provider' => 'fake',
        'provider_payment_id' => '7000001', 'paid_at' => now()->subDays(2), 'environment' => 'sandbox',
    ]);

    PaymentRefund::withoutOrganizationScope()->create([
        'organization_id' => $this->organization->id, 'payment_id' => $this->payment->id, 'provider' => 'fake',
        'provider_refund_id' => 'r-1', 'amount_cents' => 1_000, 'currency' => 'BRL', 'kind' => 'partial',
        'status' => 'approved', 'provider_status' => 'approved', 'reason' => 'Desconto', 'initiator' => 'platform_admin',
        'requested_by_user_id' => $this->admin->id, 'idempotency_key' => (string) Str::uuid(),
        'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay(),
    ]);
    PaymentChargeback::withoutOrganizationScope()->create([
        'organization_id' => $this->organization->id, 'payment_id' => $this->payment->id, 'provider' => 'fake',
        'provider_chargeback_id' => 'cb-1', 'amount_cents' => 4_900, 'currency' => 'BRL', 'reason' => 'fraud', 'received_at' => now(),
    ]);
    $run = ReconciliationRun::query()->create([
        'provider' => 'fake', 'environment' => 'sandbox', 'window_start' => now()->subDays(2), 'window_end' => now(),
        'status' => 'completed', 'trigger' => 'schedule', 'started_at' => now(), 'finished_at' => now(), 'divergence_count' => 1,
    ]);
    ReconciliationItem::query()->create([
        'reconciliation_run_id' => $run->id, 'payment_id' => $this->payment->id, 'organization_id' => $this->organization->id,
        'provider_payment_id' => '9000000001', 'divergence' => 'amount_mismatch', 'local_status' => 'approved', 'provider_status' => 'approved',
        'local_amount_cents' => 4_900, 'provider_amount_cents' => 5_900, 'local_currency' => 'BRL', 'provider_currency' => 'BRL',
    ]);

    $response = $this->actingAs($this->admin)->get(route('admin.billing.index', $this->period));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/billing')
        ->where('revenue', [
            ['currency' => 'BRL', 'approved_count' => 1, 'gross_cents' => 4_900, 'refunded_cents' => 1_000, 'charged_back_cents' => 0, 'net_cents' => 3_900],
            ['currency' => 'USD', 'approved_count' => 1, 'gross_cents' => 1_000, 'refunded_cents' => 0, 'charged_back_cents' => 0, 'net_cents' => 1_000],
        ])
        ->where('counters.open_chargebacks', 1)
        ->where('counters.past_due', 1)
        ->where('counters.open_divergences', 1)
        ->where('gateway.is_fake', true)
        ->where('recurring.enabled', false)
        ->where('fiscal.mode', 'none')
        ->has('rows.data', 2)
        ->has('rows.data.0', fn (Assert $row) => $row
            ->whereType('amount_cents', 'integer')
            ->whereType('currency', 'string')
            ->has('organization.name')
            ->etc()));

    expect($response->getContent())->not->toContain('Contrato Sigiloso XPTO');

    foreach (['refunds' => 1, 'chargebacks' => 1, 'past_due' => 1, 'divergences' => 1] as $tab => $count) {
        $page = $this->actingAs($this->admin)->get(route('admin.billing.index', [...$this->period, 'tab' => $tab]));
        $page->assertInertia(fn (Assert $inertia) => $inertia->has('rows.data', $count));
        expect($page->getContent())->not->toContain('Contrato Sigiloso XPTO');
    }
});

test('a linha de pagamento diz o que o admin pode fazer', function () {
    $pending = pendingPaymentFor($this->organization, $this->plan, $this->subscription);

    $this->actingAs($this->admin)->get(route('admin.billing.index', $this->period))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.data', fn ($rows) => collect($rows)->firstWhere('id', $this->payment->ulid)['can'] === ['refund' => true, 'cancel' => false, 'resync' => true]
                && collect($rows)->firstWhere('id', $pending->ulid)['can']['cancel'] === true
                && collect($rows)->firstWhere('id', $pending->ulid)['can']['refund'] === false));
});
