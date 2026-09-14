<?php

use App\Models\RiskReview;
use App\Models\User;
use App\Services\Risk\RiskReviewStatus;
use App\Services\Risk\RiskSignals;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Phase3/Risk/Support/RiskHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 3A — a decisão cobre um sinal que o revisor nunca viu
|--------------------------------------------------------------------------
| RiskReviewDecisions::decide() fixa `through_signal_id = MAX(id)` dos sinais da organização NO
| MOMENTO DO POST, sem conferir o que o revisor tinha na tela. Um sinal gravado entre abrir o
| caso e decidir (aqui, uma contestação de pagamento, que pode suspender o envio) fica
| "decidido" e nunca mais conta — uma liberação sem evidência revista.
*/

beforeEach(function (): void {
    $this->withoutVite();
    riskEnable();
    Notification::fake();
});

test('um sinal gravado depois de o revisor abrir o caso não é liberado junto sem ser visto', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $review = riskRestrictViaSignals($organization);
    $admin = User::factory()->platformAdmin()->create();

    // O revisor abre o caso: dois sinais na tela.
    $this->actingAs($admin)->get(route('admin.risk.show', $review))
        ->assertInertia(fn (Assert $page) => $page->has('signals', 2));

    // Chega um sinal novo enquanto ele analisa.
    $late = RiskSignals::record('payment_chargeback', $organization, ['chargebacks_in_window' => 2, 'threshold' => 2], null, 'chargeback:mp:999');
    expect($late->exists)->toBeTrue();

    // Ele libera com base no que viu.
    $this->actingAs($admin)->post(route('admin.risk.decide', $review), [
        'decision' => 'clear',
        'reason' => 'Cliente legítimo, conferido com os dois sinais da tela.',
    ]);

    $decided = RiskReview::query()->findOrFail($review->id);
    $coveredUnseen = $decided->status !== RiskReviewStatus::Open
        && $decided->through_signal_id !== null
        && $decided->through_signal_id >= $late->id;

    expect($coveredUnseen)->toBeFalse();
});
