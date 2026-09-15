<?php

use App\Enums\MembershipRole;
use App\Models\SsoIdentity;

require_once __DIR__.'/Support/SsoReviewHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão G — o ADMIN controla a conexão de SSO e, por ela, entra na conta do OWNER
|--------------------------------------------------------------------------
| As rotas de configuração aceitam `org.role:owner,admin`; só `enforce` é exclusivo do owner
| (SsoConnectionManager::update). Um admin sozinho pode:
|  - trocar `two_factor_policy` para `trust_idp` (não é campo do provedor: a conexão continua
|    ativa, sem novo teste) — e o owner passa a entrar sem o próprio 2FA;
|  - reapontar emissor/cliente para um IdP que ele controla, "testar" com o próprio e-mail,
|    ativar (setStatus não exige owner) e entrar como o owner: o vínculo por e-mail
|    (SsoLoginCompleter::resolve) não distingue owner e liga a conta dele ao sujeito novo.
| Além disso, o vínculo pelo sujeito (`sso_identities`, hash com o id da CONEXÃO, não do
| emissor) sobrevive à troca de IdP: um sujeito igual no IdP novo entra na conta antiga.
*/

beforeEach(function () {
    ['organization' => $this->org, 'owner' => $this->owner] = ssoWorkspace();
    $this->owner->forceFill(['email' => 'dono@'.SSO_DOMAIN])->save();
    $this->connection = ssoOidcConnection($this->org);
    $this->admin = ssoMember($this->org, 'admin@'.SSO_DOMAIN, MembershipRole::Admin);

    reviewG3FakeProviders($this);
});

it('admin sozinho troca a política de 2FA para trust_idp e o owner passa a entrar pelo SSO sem o próprio 2FA', function () {
    reviewG3EnableTwoFactor($this->owner);

    actingAsMember($this->admin, $this->org);
    $this->patch(route('settings.sso.connections.update', $this->connection->ulid), ['two_factor_policy' => 'trust_idp']);
    auth()->logout();

    // O IdP afirma o owner (quem administra o IdP pode fazer isso; o 2FA da conta era a barreira).
    $this->legitClaims = ['sub' => 'sujeito-dono', 'email' => 'dono@'.SSO_DOMAIN];
    $response = reviewG3OidcLogin($this, $this->connection, 'dono@'.SSO_DOMAIN);

    // Hoje: redirect ao painel, autenticado como owner, sem o desafio 2FA.
    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

it('admin sozinho reaponta a conexão para outro IdP, testa, ativa e entra na conta do owner', function () {
    // O IdP legítimo nunca afirma o owner: só a Maria. O IdP "do admin" afirma quem ele quiser.
    $this->legitClaims = [];
    actingAsMember($this->admin, $this->org);

    $this->patch(route('settings.sso.connections.update', $this->connection->ulid), [
        'oidc_issuer' => REVIEW_G3_ROTATED_ISSUER,
        'oidc_client_id' => REVIEW_G3_ROTATED_CLIENT,
        'oidc_client_secret' => 'segredo-do-admin',
    ]);

    // "Testar conexão" com o próprio e-mail do admin (domínio verificado): passa.
    $this->rotatedClaims = ['sub' => 'admin', 'email' => 'admin@'.SSO_DOMAIN];
    $params = ssoAuthorizeParams($this->post(route('settings.sso.connections.test', $this->connection->ulid)));
    $this->nonce = $params['nonce'] ?? '';
    $this->get(route('sso.oidc.callback', ['connection' => $this->connection->ulid, 'state' => $params['state'] ?? 'x', 'code' => 'c']));

    $this->post(route('settings.sso.connections.status', $this->connection->ulid), ['status' => 'active']);
    auth()->logout();

    $this->rotatedClaims = ['sub' => 'qualquer-um', 'email' => 'dono@'.SSO_DOMAIN];
    reviewG3OidcLogin($this, $this->connection->fresh(), 'dono@'.SSO_DOMAIN);

    // Hoje: autenticado como o owner (sem 2FA na conta, a política `keep` não protege).
    expect(auth()->id())->not->toBe($this->owner->id);
});

it('o vínculo pelo sujeito sobrevive à troca de IdP: sujeito igual no IdP novo entra na conta antiga, com outro e-mail', function () {
    // 1) O owner entra uma vez pelo IdP antigo com o sujeito "1".
    $this->legitClaims = ['sub' => '1', 'email' => 'dono@'.SSO_DOMAIN];
    reviewG3OidcLogin($this, $this->connection, 'dono@'.SSO_DOMAIN)->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($this->owner);
    auth()->logout();

    // 2) A organização migra para outro IdP (mudança legítima, feita pelo owner) e reativa.
    actingAsMember($this->owner, $this->org);
    $this->patch(route('settings.sso.connections.update', $this->connection->ulid), [
        'oidc_issuer' => REVIEW_G3_ROTATED_ISSUER,
        'oidc_client_id' => REVIEW_G3_ROTATED_CLIENT,
        'oidc_client_secret' => 'segredo-novo',
    ])->assertSessionHasNoErrors();
    $this->connection->refresh()->forceFill(['status' => 'active', 'last_test_status' => 'ok', 'last_tested_at' => now()])->save();
    auth()->logout();

    expect(SsoIdentity::query()->where('sso_connection_id', $this->connection->id)->where('user_id', $this->owner->id)->exists())
        ->toBeTrue(); // o vínculo do IdP antigo continua lá

    // 3) No IdP novo, o sujeito "1" é OUTRA pessoa (e-mail do estagiário, domínio verificado).
    $this->rotatedClaims = ['sub' => '1', 'email' => 'estagiario@'.SSO_DOMAIN];
    reviewG3OidcLogin($this, $this->connection->fresh(), 'estagiario@'.SSO_DOMAIN);

    // Hoje: autenticado como o owner.
    expect(auth()->id())->not->toBe($this->owner->id);
});
