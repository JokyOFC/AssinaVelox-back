<?php

use App\Enums\AuditEventType;
use App\Enums\SubscriptionStatus;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionLifecycle;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/BillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Inadimplência (RECONCILIACAO Q20)
|--------------------------------------------------------------------------
| Sem recorrência automática no Checkout Pro, o ciclo vencido sem pagamento vira
| `past_due` depois da carência (bloqueia envio) e `expired` depois disso, com a
| organização voltando ao Grátis.
*/

beforeEach(function (): void {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->owner = $owner;
    $this->free = Plan::free() ?? Plan::factory()->free()->create();
    $this->plan = paidPlan();
    $this->lifecycle = app(SubscriptionLifecycle::class);
});

test('dentro da carência a assinatura vencida continua ativa', function () {
    $subscription = subscribeOrganization($this->organization, $this->plan);
    $subscription->forceFill(['current_period_end' => Carbon::now()->subDays(2)])->save();

    expect($this->lifecycle->markPastDue())->toBe(0);

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

test('passada a carência de 3 dias a assinatura vai para past_due', function () {
    $subscription = subscribeOrganization($this->organization, $this->plan);
    $subscription->forceFill(['current_period_end' => Carbon::now()->subDays(4)])->save();

    expect($this->lifecycle->markPastDue())->toBe(1);

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue);

    expect(AuditEvent::withoutOrganizationScope()
        ->where('event_type', AuditEventType::SubscriptionPastDue->value)
        ->count())->toBe(1);
});

test('o plano gratuito nunca fica inadimplente', function () {
    $subscription = subscribeOrganization($this->organization, $this->free);
    $subscription->forceFill(['current_period_end' => Carbon::now()->subDays(90)])->save();

    expect($this->lifecycle->markPastDue())->toBe(0);
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

test('passados 15 dias a assinatura expira e a organização volta ao plano Grátis', function () {
    $subscription = subscribeOrganization($this->organization, $this->plan, state: 'past_due');
    $subscription->forceFill(['current_period_end' => Carbon::now()->subDays(20)])->save();

    expect($this->lifecycle->expire())->toBe(1);

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Expired);

    $current = $this->lifecycle->currentFor($this->organization);

    expect($current)->not->toBeNull()
        ->and($current->plan_id)->toBe($this->free->id)
        ->and($current->status)->toBe(SubscriptionStatus::Active)
        ->and($current->envelopes_used)->toBe(0)
        ->and($current->provider)->toBeNull();
});

test('o comando billing:dunning aplica as duas etapas e é idempotente', function () {
    $late = subscribeOrganization($this->organization, $this->plan);
    $late->forceFill(['current_period_end' => Carbon::now()->subDays(5)])->save();

    ['organization' => $other] = createOrganizationWithOwner();
    $expiring = subscribeOrganization($other, $this->plan, state: 'past_due');
    $expiring->forceFill(['current_period_end' => Carbon::now()->subDays(30)])->save();

    $this->artisan('billing:dunning')->assertExitCode(0);

    expect($late->refresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($expiring->refresh()->status)->toBe(SubscriptionStatus::Expired);

    $countBefore = Subscription::withoutOrganizationScope()->count();

    // Rodar de novo no mesmo dia não muda nada nem cria assinatura extra.
    $this->artisan('billing:dunning')->assertExitCode(0);

    expect(Subscription::withoutOrganizationScope()->count())->toBe($countBefore);
});

test('cancelar a renovação mantém o plano até o fim do ciclo e é reversível', function () {
    $subscription = subscribeOrganization($this->organization, $this->plan);

    actingAsMember($this->owner, $this->organization);

    $this->withSession(confirmedPasswordSession($this->organization))
        ->post(route('billing.cancel'))
        ->assertRedirect();

    $subscription->refresh();

    expect($subscription->cancel_at_period_end)->toBeTrue()
        ->and($subscription->canceled_at)->not->toBeNull()
        // Continua ativa: cancelar renovação não corta o acesso já pago.
        ->and($subscription->status)->toBe(SubscriptionStatus::Active);

    $this->post(route('billing.resume'))->assertRedirect();

    $subscription->refresh();

    expect($subscription->cancel_at_period_end)->toBeFalse()
        ->and($subscription->canceled_at)->toBeNull();

    expect(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::SubscriptionCanceled->value)->count())->toBe(1);
    expect(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::SubscriptionResumed->value)->count())->toBe(1);
});

test('cancelar a renovação exige confirmação de senha', function () {
    subscribeOrganization($this->organization, $this->plan);

    actingAsMember($this->owner, $this->organization);

    // Sem `auth.password_confirmed_at` na sessão, o middleware manda confirmar a senha.
    $this->post(route('billing.cancel'))->assertRedirect(route('password.confirm'));
});
