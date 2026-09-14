<?php

use App\Models\Affiliate;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\User;
use App\Services\Affiliates\Attribution;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/AffiliateHelpers.php';

/*
|--------------------------------------------------------------------------
| Atribuição: link → cookie → cadastro → indicação (Fase 3 §3.10)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();
    enableAffiliates();
    // Serviço real do antifraude (App\Services\Risk\RiskSignals), com a flag ligada: uma
    // indicação legítima não grava sinal nenhum.
    config()->set('assinavelox.features.antifraud', true);
});

test('o link grava o cookie de atribuição para um código aprovado e leva ao cadastro', function () {
    $affiliate = makeAffiliate();

    $response = $this->get(route('affiliates.link', ['code' => $affiliate->code]));

    $response->assertRedirect(route('register'));
    $response->assertCookie(affiliateCookieName());

    $data = json_decode((string) $response->getCookie(affiliateCookieName())?->getValue(), true);
    expect($data['c'])->toBe($affiliate->code)
        ->and($data['t'])->toBeInt()
        ->and(array_keys($data))->toBe(['c', 't']);
});

test('código desconhecido, suspenso ou recusado redireciona igual, sem cookie', function () {
    $suspended = makeAffiliate(['status' => Affiliate::STATUS_SUSPENDED]);

    foreach (['ZZZZZZZZ', $suspended->code] as $code) {
        $response = $this->get(route('affiliates.link', ['code' => $code]));
        $response->assertRedirect(route('register'));
        $response->assertCookieMissing(affiliateCookieName());
    }
});

test('cadastro dentro da janela atribui a organização criada ao afiliado', function () {
    $affiliate = makeAffiliate();

    $response = signupWithReferral('ana@clinica-norte.com.br', referralCookieValue($affiliate->code, now()->subDays(59)));

    $response->assertRedirect(route('dashboard', absolute: false));
    // O cookie cumpriu o papel e sai da resposta do cadastro.
    $response->assertCookieExpired(affiliateCookieName());

    $user = User::query()->where('email', 'ana@clinica-norte.com.br')->sole();
    $referral = Referral::query()->sole();

    expect($referral->affiliate_id)->toBe($affiliate->id)
        ->and($referral->organization_id)->toBe($user->current_organization_id)
        ->and($referral->status)->toBe(Referral::STATUS_ACTIVE)
        ->and($referral->block_reasons)->toBeNull()
        ->and($referral->source)->toBe(Referral::SOURCE_LINK)
        ->and($referral->expires_at?->toDateString())->toBe(now()->addMonths(12)->toDateString())
        ->and(DB::table('risk_signals')->where('rule_code', 'affiliate_self_referral')->count())->toBe(0);
});

test('cadastro fora da janela não atribui nada', function () {
    $affiliate = makeAffiliate();

    signupWithReferral('bruno@oficina-sul.com.br', referralCookieValue($affiliate->code, now()->subDays(61)))
        ->assertRedirect(route('dashboard', absolute: false));

    expect(Referral::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'bruno@oficina-sul.com.br')->exists())->toBeTrue();
});

test('a janela é configurável', function () {
    config()->set('assinavelox.affiliates.attribution_window_days', 7);
    $affiliate = makeAffiliate();

    signupWithReferral('carla@studio-leste.com.br', referralCookieValue($affiliate->code, now()->subDays(8)));

    expect(Referral::query()->count())->toBe(0);
});

test('primeiro toque: um cookie válido não é substituído por outro link; último toque substitui', function () {
    $first = makeAffiliate();
    $second = makeAffiliate();

    $response = $this->withCookie(affiliateCookieName(), referralCookieValue($first->code, now()->subDays(3)))
        ->get(route('affiliates.link', ['code' => $second->code]));
    $response->assertRedirect(route('register'));
    $response->assertCookieMissing(affiliateCookieName());

    config()->set('assinavelox.affiliates.attribution_model', 'last_touch');

    $response = $this->withCookie(affiliateCookieName(), referralCookieValue($first->code, now()->subDays(3)))
        ->get(route('affiliates.link', ['code' => $second->code]));
    $data = json_decode((string) $response->getCookie(affiliateCookieName())?->getValue(), true);
    expect($data['c'])->toBe($second->code);
});

test('primeiro toque: cookie vencido é substituído pelo novo link', function () {
    $first = makeAffiliate();
    $second = makeAffiliate();

    $response = $this->withCookie(affiliateCookieName(), referralCookieValue($first->code, now()->subDays(70)))
        ->get(route('affiliates.link', ['code' => $second->code]));

    $data = json_decode((string) $response->getCookie(affiliateCookieName())?->getValue(), true);
    expect($data['c'])->toBe($second->code);
});

test('a organização é atribuída uma única vez (organization_id UNIQUE)', function () {
    $first = makeAffiliate();
    $second = makeAffiliate();

    signupWithReferral('dora@agencia-oeste.com.br', referralCookieValue($first->code));

    $user = User::query()->where('email', 'dora@agencia-oeste.com.br')->sole();

    // Uma segunda tentativa (outro afiliado) para a MESMA organização não muda nada.
    $request = Request::create('/cadastro', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
    $request->cookies->set(affiliateCookieName(), referralCookieValue($second->code));

    expect(app(Attribution::class)->attributeRegistration($user, $request))->toBeNull();
    expect(Referral::query()->sole()->affiliate_id)->toBe($first->id);

    // E o banco garante: nem uma gravação direta passa.
    expect(fn () => Referral::query()->create([
        'affiliate_id' => $second->id,
        'organization_id' => $user->current_organization_id,
        'source' => Referral::SOURCE_LINK,
        'status' => Referral::STATUS_ACTIVE,
        'attributed_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('cadastro sem cookie ou por convite não gera indicação', function () {
    $affiliate = makeAffiliate();

    signupWithReferral('edu@consultoria-x.com.br');
    expect(Referral::query()->count())->toBe(0);

    // Usuário sem organização própria (ex.: veio de convite): nada a atribuir.
    $invited = User::factory()->create(['email' => 'fabi@consultoria-y.com.br']);
    $request = Request::create('/cadastro', 'POST');
    $request->cookies->set(affiliateCookieName(), referralCookieValue($affiliate->code));
    expect(app(Attribution::class)->attributeRegistration($invited, $request))->toBeNull();

    // Organização de outra pessoa como "corrente": também não.
    ['organization' => $foreign] = createOrganizationWithOwner();
    $invited->forceFill(['current_organization_id' => $foreign->id])->save();
    expect(app(Attribution::class)->attributeRegistration($invited->fresh(), $request))->toBeNull()
        ->and(Referral::query()->count())->toBe(0)
        ->and(Organization::query()->count())->toBeGreaterThan(0);
});

test('afiliado suspenso depois do clique não recebe a indicação', function () {
    $affiliate = makeAffiliate();
    $cookie = referralCookieValue($affiliate->code);
    $affiliate->forceFill(['status' => Affiliate::STATUS_SUSPENDED])->save();

    signupWithReferral('gil@empresa-z.com.br', $cookie);

    expect(Referral::query()->count())->toBe(0);
});
