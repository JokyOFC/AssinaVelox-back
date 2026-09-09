<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial — cobrança: a cota do plano Grátis nunca renova
|--------------------------------------------------------------------------
|
| DEFEITO. A cota é anunciada por CICLO, em três lugares:
|
|   - RECONCILIACAO §4 Q8: "Plano `free` permanente (limites baixos: 5 docs/mês...)";
|   - `PlanController::featureLabels()` (app/Http/Controllers/Billing/PlanController.php:130)
|     escreve literalmente "5 documentos/mês" no card de plano;
|   - `CreateOrganization::createFreePlanFallback()` (app/Services/Organizations/CreateOrganization.php:78)
|     descreve o plano como "5 documentos por mês".
|
| E o ciclo da assinatura gratuita É criado com um mês (`current_period_end = +1 mês`).
| Só que NADA no sistema renova esse ciclo nem zera `subscriptions.envelopes_used`:
|
|   - `ActivateSubscription::applyCycle()` zera o consumo, mas só é chamado por um
|     PAGAMENTO aprovado — o plano Grátis não gera pagamento nenhum;
|   - `SubscriptionLifecycle::markPastDue()` (app/Services/Billing/SubscriptionLifecycle.php:106)
|     filtra `whereHas('plan', price_cents > 0)`, então a assinatura gratuita nunca é
|     tocada pelo `billing:dunning`, o único agendamento que mexe em assinaturas
|     (routes/console.php);
|   - `PlanLedger` só soma; ele nunca reinicia o período.
|
| Resultado: `Subscription::remainingEnvelopes()` = `quota - used - reserved` fica em 0
| PARA SEMPRE depois do quinto envelope. O plano Grátis é, na prática, uma cota vitalícia
| de 5 documentos — e a organização é bloqueada com "cota esgotada" indefinidamente,
| enquanto a tela continua prometendo 5/mês. O teste
| `Billing/DunningTest::"o plano gratuito nunca fica inadimplente"` fixa exatamente o
| filtro que causa isto, sem verificar o que acontece com o ciclo.
*/

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Plans\Exceptions\SendingBlockedException;
use App\Services\Plans\PlanLedger;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

it('renova o ciclo do plano Grátis depois do mês anunciado na tela', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    /** @var Subscription $subscription */
    $subscription = Subscription::withoutOrganizationScope()
        ->where('organization_id', $organization->getKey())
        ->firstOrFail();

    expect($subscription->plan->code)->toBe(Plan::CODE_FREE)
        ->and($subscription->plan->envelope_quota)->toBe(5)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active);

    // A organização gasta a cota inteira do primeiro mês...
    $subscription->forceFill(['envelopes_used' => 5])->save();

    expect($subscription->remainingEnvelopes())->toBe(0);

    // ... e dois meses se passam. O ciclo anunciado ("5 documentos/mês") já virou duas
    // vezes. Todo o agendamento do produto roda no intervalo.
    $this->travel(2)->months();
    $this->artisan('billing:dunning')->assertExitCode(0);
    $this->artisan('envelopes:expire')->assertExitCode(0);

    $subscription = Subscription::withoutOrganizationScope()
        ->where('organization_id', $organization->getKey())
        ->latest('id')
        ->with('plan')
        ->firstOrFail();

    expect($subscription->envelopes_used)->toBe(
        0,
        'o consumo do plano Grátis nunca é zerado: a cota anunciada como mensal é vitalícia',
    );

    expect($subscription->remainingEnvelopes())->toBeGreaterThan(
        0,
        'passados dois meses a organização gratuita continua sem nenhum envelope disponível',
    );
});

it('deixa a organização gratuita enviar de novo no ciclo seguinte', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    /** @var Subscription $subscription */
    $subscription = Subscription::withoutOrganizationScope()
        ->where('organization_id', $organization->getKey())
        ->firstOrFail();

    $subscription->forceFill(['envelopes_used' => (int) $subscription->plan->envelope_quota])->save();

    $this->travel(2)->months();
    $this->artisan('billing:dunning')->assertExitCode(0);

    $ledger = app(PlanLedger::class);
    $current = $ledger->subscriptionFor($organization);

    expect($current)->not->toBeNull();

    // Hoje isto lança "cota esgotada" para sempre, mesmo dois ciclos depois.
    expect(fn () => $ledger->assertCanSend($current))
        ->not->toThrow(SendingBlockedException::class);
});
