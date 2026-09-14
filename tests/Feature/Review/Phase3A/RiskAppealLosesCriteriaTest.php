<?php

use App\Models\RiskReview;
use App\Models\User;
use App\Services\Risk\RiskDecision;
use App\Services\Risk\RiskReviewDecisions;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Phase3/Risk/Support/RiskHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 3A — o pedido de revisão (LGPD art. 20) apaga os critérios da restrição
|--------------------------------------------------------------------------
| Depois de uma restrição CONFIRMADA (ou de "manter em observação"), o pedido da organização
| abre um caso novo (`trigger = appeal`) com `after_signal_id = through_signal_id` do caso
| decidido. `RiskReview::signalsQuery()` desse caso é vazio, e:
|  - a página /revisao-de-seguranca (que lê o ÚLTIMO caso) passa a mostrar ZERO critérios,
|    embora a conta continue `restricted` pelas mesmas regras (art. 20 §1º);
|  - o caso que o revisor abre para julgar o pedido não mostra nenhum sinal nem evidência.
*/

beforeEach(function (): void {
    $this->withoutVite();
    riskEnable();
    Notification::fake();
});

test('depois de pedir revisão de uma restrição confirmada, a organização continua vendo os critérios que a restringem', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    $admin = User::factory()->platformAdmin()->create();
    app(RiskReviewDecisions::class)->decide($review, RiskDecision::Confirm, 'Denúncias de phishing confirmadas pela equipe.', $admin);

    actingAsMember($owner, $organization);

    // Antes do pedido: os dois critérios aparecem.
    $this->get(route('risk.appeal.show'))->assertInertia(fn (Assert $page) => $page
        ->where('status', 'restricted')
        ->has('rules', 2));

    $this->post(route('risk.appeal.store'), ['message' => 'Corrigimos a lista de contatos e removemos os endereços inválidos.'])
        ->assertSessionHas('success');

    // Depois do pedido: a conta segue restrita pelas MESMAS regras — os critérios não podem sumir.
    $this->get(route('risk.appeal.show'))->assertInertia(fn (Assert $page) => $page
        ->where('status', 'restricted')
        ->has('rules', 2));
});

test('o caso aberto pelo pedido de revisão mostra ao revisor os sinais que motivaram a restrição', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    $admin = User::factory()->platformAdmin()->create();
    app(RiskReviewDecisions::class)->decide($review, RiskDecision::Confirm, 'Denúncias de phishing confirmadas pela equipe.', $admin);

    actingAsMember($owner, $organization);
    $this->post(route('risk.appeal.store'), ['message' => 'Corrigimos a lista de contatos e removemos os endereços inválidos.'])
        ->assertSessionHas('success');

    $appeal = RiskReview::query()->where('organization_id', $organization->id)->where('status', 'open')->sole();

    $this->actingAs($admin)->get(route('admin.risk.show', $appeal))->assertInertia(fn (Assert $page) => $page
        ->component('admin/risk/show')
        ->where('can_decide', true)
        ->has('signals', 2));
});
