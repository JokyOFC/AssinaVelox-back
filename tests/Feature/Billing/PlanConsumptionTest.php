<?php

use App\Enums\EnvelopeStatus;
use App\Enums\PlanConsumptionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Services\Plans\PlanLedger;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/Support/BillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Consumo do plano e bloqueio de envio
|--------------------------------------------------------------------------
| A unidade de consumo é o **envelope enviado**: reserva no envio, confirmação na
| conclusão, liberação se o envio for desfeito. O bloqueio é sempre só de ENVIO —
| ler, acompanhar e baixar continuam liberados, porque o cliente não perde acesso
| ao que já é dele.
*/

beforeEach(function (): void {
    $this->withoutVite();
    fakeEmailProvider();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->owner = $owner;
});

test('cota esgotada bloqueia o envio com mensagem em PT-BR e a leitura continua', function () {
    setPlanQuota($this->organization, 2);

    $subscription = subscriptionFor($this->organization);
    $subscription->forceFill(['envelopes_used' => 2])->save();

    $envelope = readyEnvelope($this->organization, $this->owner);

    actingAsMember($this->owner, $this->organization);

    $this->post(route('envelopes.send', $envelope))
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'documentos do seu plano neste período'));

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Ready)
        ->and($envelope->sent_at)->toBeNull()
        ->and(PlanConsumption::withoutOrganizationScope()->count())->toBe(0);

    // Leitura preservada: lista, detalhe e a própria tela de cobrança.
    $this->get(route('envelopes.index'))->assertOk();
    $this->get(route('envelopes.show', $envelope))->assertOk();
    $this->get(route('billing.index'))->assertOk();
});

test('assinatura inadimplente bloqueia o envio e mantém leitura e download', function () {
    $subscription = subscriptionFor($this->organization);
    $subscription->forceFill([
        'status' => SubscriptionStatus::PastDue,
        'current_period_end' => Carbon::now()->subDays(5),
    ])->save();

    $envelope = readyEnvelope($this->organization, $this->owner);

    actingAsMember($this->owner, $this->organization);

    $this->post(route('envelopes.send', $envelope))
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'pagamento do plano está em atraso'));

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Ready);

    $this->get(route('envelopes.show', $envelope))->assertOk();
    $this->get(route('plans.index'))->assertOk();
});

test('assinatura expirada bloqueia o envio', function () {
    $subscription = subscriptionFor($this->organization);
    $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();

    $envelope = readyEnvelope($this->organization, $this->owner);

    actingAsMember($this->owner, $this->organization);

    $this->post(route('envelopes.send', $envelope))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Ready);
});

test('o envio consome uma unidade do ledger e a tela de cobrança soma usado + reservado', function () {
    setPlanQuota($this->organization, 5);

    $envelope = readyEnvelope($this->organization, $this->owner);

    actingAsMember($this->owner, $this->organization);

    $this->post(route('envelopes.send', $envelope))->assertRedirect();

    $consumption = PlanConsumption::withoutOrganizationScope()->firstOrFail();

    // A reserva do envio já é confirmada quando os convites saem; a finalização chama
    // `commit()` de novo, e ele é idempotente (nada é cobrado duas vezes).
    expect($consumption->status)->toBe(PlanConsumptionStatus::Committed)
        ->and($consumption->idempotency_key)->toBe('envelope:'.$envelope->id.':send');

    $subscription = subscriptionFor($this->organization);

    expect($subscription->envelopes_used)->toBe(1)
        ->and($subscription->envelopes_reserved)->toBe(0);

    $page = $this->get(route('billing.index'))->viewData('page');

    // A tela soma confirmado + reservado: é o total que o ledger já descontou do plano.
    expect($page['props']['usage']['envelopes']['used'])->toBe(1)
        ->and($page['props']['usage']['envelopes']['limit'])->toBe(5);
});

test('a confirmação do consumo é idempotente: chamar commit de novo não cobra outra unidade', function () {
    setPlanQuota($this->organization, 5);

    $envelope = readyEnvelope($this->organization, $this->owner);

    actingAsMember($this->owner, $this->organization);
    $this->post(route('envelopes.send', $envelope))->assertRedirect();

    $ledger = app(PlanLedger::class);
    $consumption = $ledger->forEnvelopeSend($envelope->refresh());

    expect($consumption)->not->toBeNull();

    // É exatamente a chamada que a finalização faz ao concluir o envelope.
    $ledger->commit($consumption, $envelope);
    $ledger->commit($consumption, $envelope);

    expect(subscriptionFor($this->organization)->envelopes_used)->toBe(1)
        ->and(PlanConsumption::withoutOrganizationScope()->count())->toBe(1);
});

test('a última unidade da cota é aceita e a seguinte é bloqueada', function () {
    setPlanQuota($this->organization, 1);

    $first = readyEnvelope($this->organization, $this->owner);
    $second = readyEnvelope($this->organization, $this->owner, [['name' => 'Ana', 'email' => 'ana@exemplo.com']]);

    actingAsMember($this->owner, $this->organization);

    $this->post(route('envelopes.send', $first))->assertRedirect(route('envelopes.show', ['envelope' => $first, 'sent' => 1]));

    $this->post(route('envelopes.send', $second))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Faça upgrade'));

    expect(Envelope::withoutOrganizationScope()->whereKey($second->id)->firstOrFail()->status)
        ->toBe(EnvelopeStatus::Ready);
});

test('plano ilimitado nunca bloqueia por cota', function () {
    setPlanQuota($this->organization, null);

    $subscription = subscriptionFor($this->organization);
    $subscription->forceFill(['envelopes_used' => 9_999])->save();

    $envelope = readyEnvelope($this->organization, $this->owner);

    actingAsMember($this->owner, $this->organization);

    $this->post(route('envelopes.send', $envelope))
        ->assertRedirect(route('envelopes.show', ['envelope' => $envelope, 'sent' => 1]));
});
