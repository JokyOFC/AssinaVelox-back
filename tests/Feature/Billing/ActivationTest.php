<?php

use App\Enums\AuditEventType;
use App\Enums\PaymentStatus;
use App\Enums\PlanConsumptionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AuditEvent;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanConsumption;
use App\Models\Subscription;
use App\Services\Billing\ActivateSubscription;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/BillingHelpers.php';

beforeEach(function (): void {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->owner = $owner;
    $this->free = Plan::free() ?? Plan::factory()->free()->create();
    $this->plan = paidPlan();
    $this->activator = app(ActivateSubscription::class);
});

/**
 * Pagamento aprovado, do jeito que a consulta ao provedor o deixa.
 */
function approvedPayment(object $context, ?Subscription $subscription = null): Payment
{
    $payment = pendingPaymentFor($context->organization, $context->plan, $subscription);

    $payment->forceFill([
        'status' => PaymentStatus::Approved,
        'status_detail' => 'accredited',
        'provider_payment_id' => (string) fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
        'payment_method_id' => 'pix',
        'paid_at' => Carbon::now(),
    ])->save();

    return $payment;
}

test('a ativação troca o plano, define o ciclo e zera o consumo', function () {
    $subscription = subscribeOrganization($this->organization, $this->free, used: 5);
    $payment = approvedPayment($this, $subscription);

    $activated = $this->activator->handle($payment);

    expect($activated)->not->toBeNull();

    $subscription->refresh();
    $payment->refresh();

    expect($subscription->plan_id)->toBe($this->plan->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->envelopes_used)->toBe(0)
        ->and($subscription->envelopes_reserved)->toBe(0)
        ->and($subscription->current_period_end)->not->toBeNull()
        ->and($subscription->current_period_end->isFuture())->toBeTrue()
        ->and($payment->activated_at)->not->toBeNull()
        ->and($payment->subscription_id)->toBe($subscription->id);
});

test('a ativação é idempotente: chamar duas vezes não estende o período duas vezes', function () {
    $subscription = subscribeOrganization($this->organization, $this->plan);
    $payment = approvedPayment($this, $subscription);

    $this->activator->handle($payment);

    $subscription->refresh();
    $firstEnd = $subscription->current_period_end;

    $second = $this->activator->handle($payment);

    $subscription->refresh();

    expect($second)->toBeNull()
        ->and($subscription->current_period_end?->toIso8601String())->toBe($firstEnd?->toIso8601String());
});

test('idempotente também contra corrida: uma instância desatualizada não reativa', function () {
    $subscription = subscribeOrganization($this->organization, $this->plan);
    $payment = approvedPayment($this, $subscription);

    // Duas "requisições" com a mesma linha: a segunda carrega um objeto que ainda não
    // sabe da ativação — é o retrato de duas filas processando o mesmo pagamento.
    $stale = Payment::withoutOrganizationScope()->whereKey($payment->getKey())->firstOrFail();

    $this->activator->handle($payment);

    $subscription->refresh();
    $end = $subscription->current_period_end;

    expect($stale->activated_at)->toBeNull(); // a instância antiga não sabe de nada

    $result = $this->activator->handle($stale);

    $subscription->refresh();

    expect($result)->toBeNull()
        ->and($subscription->current_period_end?->toIso8601String())->toBe($end?->toIso8601String())
        ->and(Payment::withoutOrganizationScope()->whereNotNull('activated_at')->count())->toBe(1);
});

test('pagar adiantado emenda o novo ciclo no fim do atual, sem perder dias', function () {
    $subscription = subscribeOrganization($this->organization, $this->plan);
    $subscription->forceFill(['current_period_end' => Carbon::now()->addDays(10)])->save();

    $payment = approvedPayment($this, $subscription);

    $this->activator->handle($payment);

    $subscription->refresh();

    expect($subscription->current_period_start?->isSameDay(Carbon::now()->addDays(10)))->toBeTrue()
        ->and($subscription->current_period_end?->isSameDay(Carbon::now()->addDays(10)->addMonthNoOverflow()))->toBeTrue();
});

test('reservas em aberto sobrevivem ao novo ciclo (o consumo confirmado é que zera)', function () {
    $subscription = subscribeOrganization($this->organization, $this->plan, used: 7);
    $subscription->forceFill(['envelopes_reserved' => 2])->save();

    PlanConsumption::query()->create([
        'organization_id' => $this->organization->id,
        'subscription_id' => $subscription->id,
        'idempotency_key' => 'envelope:9001:send',
        'quantity' => 2,
        'status' => PlanConsumptionStatus::Reserved,
        'reserved_at' => Carbon::now(),
    ]);

    $this->activator->handle(approvedPayment($this, $subscription));

    $subscription->refresh();

    expect($subscription->envelopes_used)->toBe(0)
        ->and($subscription->envelopes_reserved)->toBe(2);
});

test('a ativação reativa uma assinatura inadimplente e desfaz o cancelamento agendado', function () {
    $subscription = subscribeOrganization($this->organization, $this->plan, state: 'past_due');
    $subscription->forceFill(['cancel_at_period_end' => true, 'canceled_at' => Carbon::now()->subDay()])->save();

    $this->activator->handle(approvedPayment($this, $subscription));

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->cancel_at_period_end)->toBeFalse()
        ->and($subscription->canceled_at)->toBeNull();
});

test('um pagamento que não está aprovado nunca ativa nada', function () {
    $subscription = subscribeOrganization($this->organization, $this->free);
    $payment = pendingPaymentFor($this->organization, $this->plan, $subscription);

    expect($this->activator->handle($payment))->toBeNull();

    $subscription->refresh();

    expect($subscription->plan_id)->toBe($this->free->id);
});

test('a ativação deixa trilha auditável na organização, sem dado sensível', function () {
    $subscription = subscribeOrganization($this->organization, $this->free);
    $payment = approvedPayment($this, $subscription);

    $this->activator->handle($payment);

    $event = AuditEvent::withoutOrganizationScope()
        ->where('event_type', AuditEventType::SubscriptionActivated->value)
        ->firstOrFail();

    expect($event->organization_id)->toBe($this->organization->id)
        ->and($event->envelope_id)->toBeNull()
        ->and($event->payload['plan'])->toBe($this->plan->code)
        ->and($event->payload['amount_cents'])->toBe((int) $this->plan->price_cents)
        ->and($event->payload['currency'])->toBe('BRL')
        ->and(json_encode($event->payload))->not->toContain('@');
});
