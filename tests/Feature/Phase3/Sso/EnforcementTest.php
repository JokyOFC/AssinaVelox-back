<?php

use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Services\Sso\Notifications\SsoBreakGlassNotification;
use App\Services\Sso\SsoSession;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/Support/SsoHelpers.php';

/*
|--------------------------------------------------------------------------
| G-SSO — login corporativo obrigatório com "break-glass" (docs/fase-3/sso.md §5)
|--------------------------------------------------------------------------
| Membros entram só por SSO; owners mantêm o acesso por senha + 2FA, que gera alerta e trilha.
| Desligar a conexão (ou a flag) nunca tranca a organização.
*/

beforeEach(function () {
    ['organization' => $this->org, 'owner' => $this->owner] = ssoWorkspace();
    $this->connection = ssoOidcConnection($this->org, ['enforce' => true]);
    $this->member = ssoMember($this->org);
    $this->admin = ssoMember($this->org, 'admin@'.SSO_DOMAIN, MembershipRole::Admin);
    $this->key = ssoRsaKey('k1');
    $this->nonce = '';

    $test = $this;
    ssoFakeOidcProvider([$this->key['jwk']], fn (HttpRequest $request) => [
        'id_token' => ssoIdToken(ssoClaims($test->nonce), $test->key['private']),
    ]);
});

it('manda o membro que entrou por senha para o login corporativo', function () {
    actingAsMember($this->member, $this->org);

    $this->get(route('dashboard'))->assertRedirect(route('sso.required'));
    $this->get(route('envelopes.index'))->assertRedirect(route('sso.required'));
    $this->getJson(route('dashboard'))->assertForbidden();

    $this->get(route('sso.required'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/sso-required')->where('organization.name', 'Empresa Exemplo'));
});

it('libera o membro depois de entrar pelo SSO da organização', function () {
    actingAsMember($this->member, $this->org);

    $response = $this->post(route('sso.required.start'));
    $params = ssoAuthorizeParams($response);
    expect($params['login_hint'])->toBe('maria@'.SSO_DOMAIN);
    $this->nonce = $params['nonce'];

    $this->get(route('sso.oidc.callback', ['connection' => $this->connection->ulid, 'state' => $params['state'], 'code' => 'c']))
        ->assertRedirect(config('fortify.home'));

    $this->get(route('dashboard'))->assertOk();
    expect(session(SsoSession::AUTHENTICATED))->toBe([(string) $this->org->id => $this->connection->id]);
});

it('mantém sair e trocar de organização acessíveis', function () {
    actingAsMember($this->member, $this->org);

    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('dá ao owner com 2FA o acesso de emergência, com trilha e alerta uma vez por sessão', function () {
    Notification::fake();
    $this->owner->forceFill(['two_factor_secret' => encrypt('SEGREDO'), 'two_factor_confirmed_at' => now()])->save();
    actingAsMember($this->owner, $this->org);

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('envelopes.index'))->assertOk();

    expect(AuditEvent::withoutOrganizationScope()->where('event_type', 'sso.break_glass_used')->count())->toBe(1)
        ->and(ssoLastAudit($this->org, 'sso.break_glass_used'))->toMatchArray(['connection' => $this->connection->ulid, 'two_factor' => true]);

    Notification::assertSentTo($this->admin, SsoBreakGlassNotification::class);
    Notification::assertNotSentTo($this->owner, SsoBreakGlassNotification::class);
    Notification::assertNotSentTo($this->member, SsoBreakGlassNotification::class);
});

it('owner sem 2FA não entra só com senha: vai ativar o 2FA', function () {
    actingAsMember($this->owner, $this->org);

    $this->get(route('dashboard'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('warning');

    expect(ssoLastAudit($this->org, 'sso.break_glass_used'))->toBeNull();
});

it('desativar a conexão nunca tranca a organização', function () {
    $this->connection->forceFill(['status' => 'disabled'])->save();
    actingAsMember($this->member, $this->org);

    $this->get(route('dashboard'))->assertOk();
});

it('desligar a flag suspende a exigência na hora', function () {
    config()->set('assinavelox.features.sso_oidc', false);
    config()->set('assinavelox.features.sso_saml', false);
    actingAsMember($this->member, $this->org);

    $this->get(route('dashboard'))->assertOk();
});

it('sem obrigatoriedade, membro entra por senha normalmente', function () {
    $this->connection->forceFill(['enforce' => false])->save();
    actingAsMember($this->member, $this->org);

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('sso.required'))->assertRedirect(config('fortify.home'));
});

it('sessão SSO de uma organização não vale para outra com SSO obrigatório', function () {
    ['organization' => $other] = createOrganizationWithOwner(['name' => 'Outra']);
    ssoEnable($other);
    ssoOidcConnection($other, ['enforce' => true]);
    attachMember($other, MembershipRole::Member, user: $this->member);

    actingAsMember($this->member, $other);
    session()->put(SsoSession::AUTHENTICATED, [(string) $this->org->id => $this->connection->id]);

    $this->get(route('dashboard'))->assertRedirect(route('sso.required'));
});

it('só o owner liga ou desliga a obrigatoriedade', function () {
    $this->admin->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()])->save();
    actingAsMember($this->admin, $this->org);
    session()->put(SsoSession::AUTHENTICATED, [(string) $this->org->id => $this->connection->id]);

    $this->patch(route('settings.sso.connections.update', $this->connection->ulid), ['enforce' => false])
        ->assertSessionHasErrors('enforce');
    expect($this->connection->fresh()->enforce)->toBeTrue();

    $this->owner->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()])->save();
    actingAsMember($this->owner, $this->org);

    $this->patch(route('settings.sso.connections.update', $this->connection->ulid), ['enforce' => false])
        ->assertSessionHasNoErrors();
    expect($this->connection->fresh()->enforce)->toBeFalse();
});

it('obrigatoriedade exige a conexão ativa', function () {
    $this->connection->forceFill(['status' => 'draft', 'enforce' => false])->save();
    actingAsMember($this->owner, $this->org);

    $this->patch(route('settings.sso.connections.update', $this->connection->ulid), ['enforce' => true])
        ->assertSessionHasErrors('enforce');
    expect($this->connection->fresh()->enforce)->toBeFalse();
});

it('o admin pode desativar a conexão mesmo com a obrigatoriedade — e ela cai junto', function () {
    actingAsMember($this->admin, $this->org);
    session()->put(SsoSession::AUTHENTICATED, [(string) $this->org->id => $this->connection->id]);

    $this->post(route('settings.sso.connections.status', $this->connection->ulid), ['status' => 'disabled'])
        ->assertSessionHasNoErrors();

    expect($this->connection->fresh()->status->value)->toBe('disabled')
        ->and($this->connection->fresh()->enforce)->toBeFalse();
});
