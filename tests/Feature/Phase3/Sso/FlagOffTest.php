<?php

use App\Enums\MembershipRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Support/SsoHelpers.php';

/*
|--------------------------------------------------------------------------
| G-SSO com as flags desligadas (roadmap T8; docs/fase-3/sso.md §1)
|--------------------------------------------------------------------------
| Nada muda: rotas 404, tela de login sem "Entrar com SSO", props `false` e nenhuma
| exigência de SSO — mesmo com uma conexão obrigatória gravada no banco.
*/

beforeEach(function () {
    ['organization' => $this->org, 'owner' => $this->owner] = createOrganizationWithOwner();
    ssoFakeDns();
    Http::preventStrayRequests();
});

it('mantém as chaves de feature desligadas por padrão, inclusive sem organização', function () {
    expect(HandleInertiaRequests::features(null))->toMatchArray(['sso_oidc' => false, 'sso_saml' => false])
        ->and(HandleInertiaRequests::features($this->org))->toMatchArray(['sso_oidc' => false, 'sso_saml' => false]);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/login')->where('features.sso_oidc', false)->where('features.sso_saml', false));
});

it('só o interruptor global liga a entrada na tela de login; o plano é conferido pela organização', function () {
    config()->set('assinavelox.features.sso_oidc', true);

    expect(HandleInertiaRequests::features(null)['sso_oidc'])->toBeTrue()
        ->and(HandleInertiaRequests::features($this->org)['sso_oidc'])->toBeFalse();
});

it('responde 404 em todas as rotas novas', function () {
    $connection = ssoOidcConnection($this->org);
    $saml = '01HZZZZZZZZZZZZZZZZZZZZZZZ';

    $this->post(route('sso.login.start'), ['email' => 'a@'.SSO_DOMAIN])->assertNotFound();
    $this->get(route('sso.oidc.callback', ['connection' => $connection->ulid, 'state' => 'x', 'code' => 'y']))->assertNotFound();
    $this->post(route('sso.saml.acs', ['connection' => $saml]), ['SAMLResponse' => 'x'])->assertNotFound();
    $this->get(route('sso.saml.metadata', ['connection' => $saml]))->assertNotFound();

    $this->actingAs($this->owner);
    $this->get(route('sso.required'))->assertNotFound();
    $this->get(route('settings.sso'))->assertNotFound();
    $this->post(route('settings.sso.connections.store'), ['protocol' => 'oidc'])->assertNotFound();
    $this->post(route('settings.sso.domains.store'), ['domain' => SSO_DOMAIN])->assertNotFound();

    Http::assertNothingSent();
});

it('com o plano sem a flag, a conexão gravada não autentica (404) mesmo com o interruptor global ligado', function () {
    config()->set('assinavelox.features.sso_oidc', true);
    $connection = ssoOidcConnection($this->org);

    $this->get(route('sso.oidc.callback', ['connection' => $connection->ulid, 'state' => 'x', 'code' => 'y']))->assertNotFound();
});

it('não exige SSO com a flag desligada, mesmo com conexão obrigatória no banco', function () {
    ssoOidcConnection($this->org, ['enforce' => true]);
    $member = attachMember($this->org, MembershipRole::Member);

    actingAsMember($member, $this->org);
    $this->get(route('dashboard'))->assertOk();
});

it('a tela Geral continua com a chave de SSO desabilitada da Fase 1', function () {
    actingAsMember($this->owner, $this->org);

    $this->get(route('settings.general'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('security.sso_enabled', false)->where('features.sso_oidc', false));
});
