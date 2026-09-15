<?php

use App\Enums\MembershipRole;
use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use PragmaRX\Google2FA\Google2FA;

require_once __DIR__.'/Support/SsoReviewHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda G — regressões das CORREÇÕES de SSO
|--------------------------------------------------------------------------
| Os testes dos achados provam que a porta fechou; estes provam que o caminho LEGÍTIMO continua
| aberto depois de cada trava nova: o owner migra de IdP e volta a entrar; o `trust_idp` vale na
| organização da conexão e, em outra, o código digitado libera; o admin ainda faz o que é dele;
| o owner com 2FA liga a obrigatoriedade.
*/

beforeEach(function () {
    ['organization' => $this->org, 'owner' => $this->owner] = ssoWorkspace();
    $this->owner->forceFill(['email' => 'dono@'.SSO_DOMAIN])->save();
    $this->connection = ssoOidcConnection($this->org);

    reviewG3FakeProviders($this);
});

it('depois da troca de IdP feita pelo owner, o vínculo antigo é invalidado e o owner o refaz pelo e-mail no IdP novo', function () {
    $this->legitClaims = ['sub' => 'dono-antigo', 'email' => 'dono@'.SSO_DOMAIN];
    reviewG3OidcLogin($this, $this->connection, 'dono@'.SSO_DOMAIN)->assertRedirect(config('fortify.home'));
    auth()->logout();

    actingAsMember($this->owner, $this->org);
    $this->patch(route('settings.sso.connections.update', $this->connection->ulid), [
        'oidc_issuer' => REVIEW_G3_ROTATED_ISSUER,
        'oidc_client_id' => REVIEW_G3_ROTATED_CLIENT,
        'oidc_client_secret' => 'segredo-novo',
    ])->assertSessionHasNoErrors();

    expect(ssoLastAudit($this->org, 'sso.connection_updated')['identities_invalidated'] ?? null)->toBe(1)
        ->and(SsoIdentity::query()->where('user_id', $this->owner->id)->sole()->isInvalidated())->toBeTrue();

    $this->connection->refresh()->forceFill(['status' => 'active', 'last_test_status' => 'ok', 'last_tested_at' => now()])->save();
    auth()->logout();

    $this->rotatedClaims = ['sub' => 'dono-novo', 'email' => 'dono@'.SSO_DOMAIN];
    reviewG3OidcLogin($this, $this->connection->fresh(), 'dono@'.SSO_DOMAIN)->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($this->owner);
    expect(SsoIdentity::query()->where('user_id', $this->owner->id)->sole()->subject_hash)
        ->toBe(SsoIdentity::hashSubject($this->connection->id, 'dono-novo'));
});

it('trust_idp vale na organização da conexão; ao abrir outra organização o código do 2FA é pedido e, digitado, libera', function () {
    $this->connection->forceFill(['two_factor_policy' => 'trust_idp'])->save();
    $maria = ssoMember($this->org);
    $google = new Google2FA;
    $secret = $google->generateSecretKey();
    $maria->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => now()])->save();
    ['organization' => $pessoal] = createOrganizationWithOwner(['name' => 'Empresa Pessoal da Maria'], $maria);

    reviewG3OidcLogin($this, $this->connection, $maria->email)->assertRedirect(config('fortify.home'));
    $this->get(route('dashboard'))->assertOk();

    $this->post(route('organizations.switch', $pessoal));
    $this->get(route('dashboard'))->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $this->post(route('two-factor.login.store'), ['code' => $google->getCurrentOtp($secret)])->assertRedirect();

    $this->assertAuthenticatedAs($maria);
    $this->get(route('dashboard'))->assertOk();
});

it('o admin muda o nome, desativa e remove; não conecta, não ativa e não muda os campos do owner', function () {
    $admin = ssoMember($this->org, 'admin@'.SSO_DOMAIN, MembershipRole::Admin);
    actingAsMember($admin, $this->org);
    $update = route('settings.sso.connections.update', $this->connection->ulid);

    $this->patch($update, ['jit_role' => 'admin'])->assertSessionHasErrors('jit_role');
    $this->patch($update, ['two_factor_policy' => 'trust_idp'])->assertSessionHasErrors('two_factor_policy');
    $this->patch($update, ['name' => 'Login da Empresa Exemplo'])->assertSessionHasNoErrors();
    expect($this->connection->fresh()->name)->toBe('Login da Empresa Exemplo');

    $this->post(route('settings.sso.connections.status', $this->connection->ulid), ['status' => 'disabled'])->assertSessionHasNoErrors();
    $this->post(route('settings.sso.connections.status', $this->connection->ulid), ['status' => 'active'])->assertSessionHasErrors('status');
    expect($this->connection->fresh()->status->value)->toBe('disabled');

    $this->delete(route('settings.sso.connections.destroy', $this->connection->ulid))->assertRedirect();
    $this->post(route('settings.sso.connections.store'), [
        'protocol' => 'oidc',
        'name' => 'Login do admin',
        'oidc_issuer' => SSO_ISSUER,
        'oidc_client_id' => SSO_CLIENT_ID,
        'oidc_client_secret' => SSO_CLIENT_SECRET,
    ])->assertSessionHasErrors('protocol');

    expect(SsoConnection::withoutOrganizationScope()->count())->toBe(0);
});

it('o owner com autenticação em duas etapas liga o login corporativo obrigatório', function () {
    reviewG3EnableTwoFactor($this->owner);
    actingAsMember($this->owner, $this->org);

    $this->patch(route('settings.sso.connections.update', $this->connection->ulid), ['enforce' => true])
        ->assertSessionHasNoErrors();

    expect($this->connection->fresh()->enforce)->toBeTrue();
});
