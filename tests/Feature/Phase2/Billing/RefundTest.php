<?php

use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Jobs\Billing\ResolveUnknownRefund;
use App\Models\PaymentRefund;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\RequestRefund;
use App\Services\Billing\SubscriptionLifecycle;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/ExtendedBillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Estornos (roadmap §2.20) — idempotência, timeout e política no plano
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    ['gateway' => $this->gateway, 'organization' => $this->organization, 'owner' => $this->owner,
        'plan' => $this->plan, 'subscription' => $this->subscription, 'payment' => $this->payment] = extendedBillingContext();

    $this->admin = User::factory()->platformAdmin()->create();
    $this->refund = fn (array $data = []) => adminBillingPost($this->admin, 'admin.billing.payments.refund', ['payment' => $this->payment->ulid], [
        'reason' => 'Cobrança em duplicidade',
        'idempotency_key' => (string) Str::uuid(),
        ...$data,
    ]);
});

test('estorno total pelo admin: chave de idempotência, confirmação do provedor e o plano não renova', function () {
    $key = (string) Str::uuid();

    ($this->refund)(['idempotency_key' => $key])->assertSessionHas('success');

    $refund = PaymentRefund::withoutOrganizationScope()->sole();

    expect($refund->status)->toBe(PaymentRefund::STATUS_APPROVED)
        ->and($refund->kind)->toBe(PaymentRefund::KIND_TOTAL)
        ->and($refund->amount_cents)->toBe(4_900)
        ->and($refund->currency)->toBe('BRL')
        ->and($refund->idempotency_key)->toBe($key)
        ->and($refund->initiator)->toBe(PaymentRefund::INITIATOR_PLATFORM_ADMIN)
        ->and($refund->requested_by_user_id)->toBe($this->admin->id)
        ->and($refund->provider_refund_id)->not->toBeNull()
        ->and($refund->confirmed_at)->not->toBeNull();

    // Total = corpo sem `amount`, com a chave gravada antes da chamada.
    expect($this->gateway->refundRequests())->toBe([
        ['payment' => '9000000001', 'amount_cents' => null, 'idempotency_key' => $key],
    ]);

    // O estado do pagamento veio da CONSULTA ao provedor.
    $payment = $this->payment->fresh();
    expect($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refunded_cents)->toBe(4_900);

    // Política conservadora: vale até o fim do ciclo, sem renovar; a cota consumida fica.
    $subscription = $this->subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->cancel_at_period_end)->toBeTrue()
        ->and($subscription->paid_cycle_refunded_at)->not->toBeNull()
        ->and($subscription->envelopes_used)->toBe(3);
});

test('repetir o mesmo pedido não cria um segundo estorno', function () {
    $key = (string) Str::uuid();

    ($this->refund)(['idempotency_key' => $key])->assertSessionHas('success');
    ($this->refund)(['idempotency_key' => $key])->assertSessionHas('success');

    expect(PaymentRefund::withoutOrganizationScope()->count())->toBe(1)
        ->and($this->gateway->refundRequests())->toHaveCount(1)
        ->and($this->gateway->refundsFor('9000000001'))->toHaveCount(1);

    // Já estornado por inteiro: um pedido novo não vai ao provedor.
    ($this->refund)()->assertSessionHas('error');
    expect($this->gateway->refundRequests())->toHaveCount(1);
});

test('timeout depois de aplicar: fica desconhecido, consulta antes de repetir e não duplica', function () {
    Queue::fake();
    $this->gateway->failNextAfterApplying(PaymentGatewayException::inconclusive('create_refund'));
    $key = (string) Str::uuid();

    ($this->refund)(['idempotency_key' => $key])->assertSessionHas('warning');

    $refund = PaymentRefund::withoutOrganizationScope()->sole();
    expect($refund->status)->toBe(PaymentRefund::STATUS_UNKNOWN)
        // O provedor aplicou; do nosso lado ninguém afirma nada ainda.
        ->and($this->gateway->refundsFor('9000000001'))->toHaveCount(1)
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Approved);

    Queue::assertPushed(ResolveUnknownRefund::class, fn (ResolveUnknownRefund $job) => $job->refundId === $refund->id);

    (new ResolveUnknownRefund($refund->id))->handle(app(RequestRefund::class));

    $refund->refresh();
    expect($refund->status)->toBe(PaymentRefund::STATUS_APPROVED)
        ->and($refund->provider_refund_id)->toBe($this->gateway->refundsFor('9000000001')[0]->refundId)
        // Achado pela consulta: nenhuma segunda chamada de estorno.
        ->and($this->gateway->refundRequests())->toHaveCount(1)
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Refunded);

    ($this->refund)(['idempotency_key' => $key]);
    expect($this->gateway->refundRequests())->toHaveCount(1);
});

test('timeout antes de aplicar: a consulta não acha nada e a repetição usa a MESMA chave', function () {
    Queue::fake();
    $this->gateway->failNextWith(PaymentGatewayException::inconclusive('create_refund'));
    $key = (string) Str::uuid();

    ($this->refund)(['idempotency_key' => $key])->assertSessionHas('warning');
    $refund = PaymentRefund::withoutOrganizationScope()->sole();

    // Enquanto o primeiro está sem confirmação, outro pedido para o mesmo pagamento é recusado.
    ($this->refund)()->assertSessionHas('error');
    expect(PaymentRefund::withoutOrganizationScope()->count())->toBe(1);

    app(RequestRefund::class)->resolveUnknown($refund);

    expect($refund->fresh()->status)->toBe(PaymentRefund::STATUS_APPROVED)
        ->and(array_column($this->gateway->refundRequests(), 'idempotency_key'))->toBe([$key, $key])
        ->and($this->gateway->refundsFor('9000000001'))->toHaveCount(1);
});

test('estorno parcial não altera plano nem cota', function () {
    ($this->refund)(['amount_cents' => 1_000, 'reason' => 'Desconto concedido'])->assertSessionHas('success');

    $refund = PaymentRefund::withoutOrganizationScope()->sole();
    expect($refund->kind)->toBe(PaymentRefund::KIND_PARTIAL)
        ->and($refund->amount_cents)->toBe(1_000)
        ->and($this->gateway->refundRequests()[0]['amount_cents'])->toBe(1_000);

    $payment = $this->payment->fresh();
    expect($payment->status)->toBe(PaymentStatus::Approved)
        ->and($payment->status_detail)->toBe('partially_refunded')
        ->and($payment->refunded_cents)->toBe(1_000)
        ->and($payment->refundableCents())->toBe(3_900);

    $subscription = $this->subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->cancel_at_period_end)->toBeFalse()
        ->and($subscription->paid_cycle_refunded_at)->toBeNull()
        ->and($subscription->envelopes_used)->toBe(3);
});

test('política: com estorno total a conta volta ao Grátis no fim do período, sem passar por inadimplência', function () {
    ensureFreePlan();
    ($this->refund)()->assertSessionHas('success');

    $end = $this->subscription->fresh()->current_period_end;
    $lifecycle = app(SubscriptionLifecycle::class);

    $this->travelTo($end->copy()->subHour());
    expect($lifecycle->runDunning()['expired'])->toBe(0)
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

    // Passada a carência que levaria um ciclo não pago a `past_due`, o estornado nunca vai para lá.
    $this->travelTo($end->copy()->addDays(4));
    $result = $lifecycle->runDunning();

    expect($result['past_due'])->toBe(0)
        ->and($result['expired'])->toBe(1)
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Expired);

    $current = Subscription::withoutOrganizationScope()->where('organization_id', $this->organization->id)->latest('id')->first();
    expect($current->plan->code)->toBe(Plan::CODE_FREE)
        ->and($current->status)->toBe(SubscriptionStatus::Active);
});

test('um novo pagamento aprovado encerra o efeito do estorno total', function () {
    ($this->refund)()->assertSessionHas('success');

    $next = pendingPaymentFor($this->organization, $this->plan, $this->subscription);
    $this->gateway->pretendPayment('9000000002', $next->external_reference, (int) $this->plan->price_cents);
    postMercadoPagoWebhook('9000000002', billingSecret())->assertOk();

    $subscription = $this->subscription->fresh();
    expect($subscription->paid_cycle_refunded_at)->toBeNull()
        ->and($subscription->cancel_at_period_end)->toBeFalse();
});

test('estorno exige administrador da plataforma com senha confirmada', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.billing.payments.refund', $this->payment->ulid), ['reason' => 'Sem confirmar senha', 'idempotency_key' => (string) Str::uuid()])
        ->assertRedirect();

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.billing.payments.refund', $this->payment->ulid), ['reason' => 'Dono tentando pelo painel', 'idempotency_key' => (string) Str::uuid()])
        ->assertForbidden();

    expect(PaymentRefund::withoutOrganizationScope()->count())->toBe(0)
        ->and($this->gateway->refundRequests())->toBe([]);
});

test('prazo de 180 dias, valor acima do saldo e motivo obrigatório são recusados sem chamar o provedor', function () {
    $old = activatedPaymentFor($this->organization, $this->plan, $this->subscription, $this->gateway, '9000000099', paidDaysAgo: 181);

    adminBillingPost($this->admin, 'admin.billing.payments.refund', ['payment' => $old->ulid], [
        'reason' => 'Antigo demais', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHas('error', fn (string $message) => str_contains($message, '180 dias'));

    ($this->refund)(['amount_cents' => 99_999])->assertSessionHas('error', fn (string $message) => str_contains($message, 'R$ 49,00'));
    ($this->refund)(['reason' => ''])->assertSessionHasErrors('reason');

    expect($this->gateway->refundRequests())->toBe([]);
});

test('o proprietário só pede estorno quando a política permite, só o integral e dentro da janela', function () {
    $ownerRefund = fn (User $user) => $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('billing.payments.refund', $this->payment->ulid), ['reason' => 'Contratei por engano', 'idempotency_key' => (string) Str::uuid()]);

    // Padrão: só a equipe da plataforma.
    $ownerRefund($this->owner)->assertForbidden();

    config()->set('assinavelox.billing.refunds.initiators', 'platform_admin,owner');

    // Administrador da organização não é proprietário.
    $ownerRefund(attachMember($this->organization, MembershipRole::Admin))->assertForbidden();
    expect($this->gateway->refundRequests())->toBe([]);

    $ownerRefund($this->owner)->assertSessionHas('success');

    $refund = PaymentRefund::withoutOrganizationScope()->sole();
    expect($refund->initiator)->toBe(PaymentRefund::INITIATOR_OWNER)
        ->and($refund->kind)->toBe(PaymentRefund::KIND_TOTAL)
        ->and($refund->requested_by_user_id)->toBe($this->owner->id);
});

test('fora da janela do proprietário o pedido é recusado', function () {
    config()->set('assinavelox.billing.refunds.initiators', 'platform_admin,owner');
    config()->set('assinavelox.billing.refunds.owner_window_days', 7);

    $late = activatedPaymentFor($this->organization, $this->plan, $this->subscription, $this->gateway, '9000000050', paidDaysAgo: 10);

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('billing.payments.refund', $late->ulid), ['reason' => 'Fora do prazo', 'idempotency_key' => (string) Str::uuid()])
        ->assertSessionHas('error', fn (string $message) => str_contains($message, '7 dias'));

    expect($this->gateway->refundRequests())->toBe([]);
});
