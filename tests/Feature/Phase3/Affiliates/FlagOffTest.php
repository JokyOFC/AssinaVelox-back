<?php

use App\Models\Commission;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\User;
use App\Services\Affiliates\CommissionLedger;

require_once __DIR__.'/Support/AffiliateHelpers.php';

/*
|--------------------------------------------------------------------------
| Flag `affiliates` desligada (o padrão): nada muda — cadastro inclusive.
|--------------------------------------------------------------------------
| O provider é registrado mesmo assim, para provar que gancho e comando não fazem nada.
*/

beforeEach(function (): void {
    $this->withoutVite();
    enableAffiliates(false);
});

test('a flag nasce desligada', function () {
    expect(config('assinavelox.features.affiliates'))->toBeFalse();
})->skip(fn () => (bool) env('ASSINAVELOX_FEATURE_AFFILIATES', false), 'ambiente com a flag ligada');

test('todas as rotas do programa respondem 404', function () {
    $affiliate = makeAffiliate();
    ['referral' => $referral] = referOrganization($affiliate);
    $admin = User::factory()->platformAdmin()->create();

    $this->get(route('affiliates.link', ['code' => $affiliate->code]))->assertNotFound();

    $this->actingAs($affiliate->user)->get(route('affiliates.index'))->assertNotFound();
    $this->actingAs($affiliate->user)->post(route('affiliates.apply'), [...affiliatePayoutInput(), 'terms' => true])->assertNotFound();
    $this->actingAs($affiliate->user)->get(route('affiliates.commissions.export'))->assertNotFound();
    $this->actingAs($affiliate->user)->post(route('affiliates.referrals.review', $referral))->assertNotFound();

    $this->actingAs($admin)->get(route('admin.affiliates.index'))->assertNotFound();
    $this->actingAs($admin)->get(route('admin.affiliates.show', $affiliate))->assertNotFound();
    $this->actingAs($admin)->get(route('admin.affiliates.payouts.index'))->assertNotFound();
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('admin.affiliates.rate.update', $affiliate), ['commission_rate_bp' => 1, 'reason' => 'Teste flag.'])->assertNotFound();
});

test('cadastro com cookie de indicação é exatamente o cadastro de sempre', function () {
    $affiliate = makeAffiliate();

    $response = signupWithReferral('nina@loja-teta.com.br', referralCookieValue($affiliate->code));

    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertCookieMissing(affiliateCookieName());
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'nina@loja-teta.com.br')->sole();
    $organization = Organization::query()->whereKey($user->current_organization_id)->sole();

    expect($organization->created_by_user_id)->toBe($user->id)
        ->and(Membership::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Referral::query()->count())->toBe(0);
});

test('pagamento aprovado não gera comissão e o comando não faz nada', function () {
    $affiliate = makeAffiliate();
    ['organization' => $organization] = referOrganization($affiliate);

    paymentFor($organization, paidAt: now()->subDays(40));
    app(CommissionLedger::class)->sweep();
    app(CommissionLedger::class)->approveDue();

    $this->artisan('affiliates:settle')
        ->expectsOutputToContain('desligado')
        ->assertSuccessful();

    expect(Commission::query()->count())->toBe(0);
});
