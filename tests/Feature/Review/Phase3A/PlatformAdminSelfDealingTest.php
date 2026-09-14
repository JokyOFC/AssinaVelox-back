<?php

use App\Models\Affiliate;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../../Phase3/Affiliates/Support/AffiliateHelpers.php';
require_once __DIR__.'/../../Phase3/Risk/Support/RiskHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 3A — decisão "humana" tomada pela própria parte interessada
|--------------------------------------------------------------------------
| Nenhuma decisão do painel confere se o revisor é a parte afetada:
|  - AffiliateProgram::approve()/changeRate(): um platform admin aprova a PRÓPRIA candidatura
|    de afiliado e escolhe a própria taxa (até o teto de 50%);
|  - RiskReviewDecisions::decide(): um platform admin que é owner de uma organização
|    `restricted` libera o PRÓPRIO caso de antifraude.
| A trilha registra a decisão, mas o "revisor" é o beneficiário — a revisão humana exigida pelo
| roadmap §3.7/§3.10 (e a revisão do art. 20) vira autoaprovação.
*/

beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
});

test('platform admin não aprova a própria candidatura de afiliado nem escolhe a própria taxa', function () {
    enableAffiliates();
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->post(route('affiliates.apply'), [...affiliatePayoutInput(), 'terms' => true])
        ->assertSessionHas('success');

    $affiliate = Affiliate::query()->where('user_id', $admin->id)->sole();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.affiliates.approve', $affiliate), ['commission_rate_bp' => 5000]);

    $affiliate->refresh();

    expect($affiliate->status)->toBe(Affiliate::STATUS_PENDING)
        ->and($affiliate->approved_by_user_id)->not->toBe($admin->id);
});

test('platform admin que é dono da organização não libera o próprio caso de antifraude', function () {
    riskEnable();
    $admin = User::factory()->platformAdmin()->create();
    ['organization' => $organization] = createOrganizationWithOwner(owner: $admin);
    $review = riskRestrictViaSignals($organization);

    $this->actingAs($admin)->post(route('admin.risk.decide', $review), [
        'decision' => 'clear',
        'reason' => 'Minha própria empresa, está tudo certo.',
    ]);

    expect($review->fresh()->reviewer_id)->not->toBe($admin->id)
        ->and(riskStatusOf($organization))->toBe('restricted');
});
