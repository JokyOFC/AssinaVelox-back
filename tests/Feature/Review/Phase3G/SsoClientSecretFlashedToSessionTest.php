<?php

use App\Models\SsoConnection;

require_once __DIR__.'/Support/SsoReviewHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão G — client secret do OIDC gravado EM CLARO na sessão quando a validação falha
|--------------------------------------------------------------------------
| SsoSettingsController::store()/update() chamam `$request->validate()` antes do `try`. Na
| falha, o Laravel devolve o redirect com `withInput()` de tudo, menos `dontFlash`
| (current_password, password, password_confirmation) — o `oidc_client_secret` vai para a
| sessão. Com SESSION_DRIVER=database e SESSION_ENCRYPT=false (.env.example), o segredo fica
| em claro na tabela `sessions`, contra a regra "segredos sempre cifrados em repouso". O
| `withInput($request->except('oidc_client_secret'))` do controller só cobre a falha de negócio.
*/

beforeEach(function () {
    ['organization' => $this->org, 'owner' => $this->owner] = ssoWorkspace();
    actingAsMember($this->owner, $this->org);
});

it('cadastro com erro de validação não guarda o client secret na sessão', function () {
    $this->post(route('settings.sso.connections.store'), [
        'protocol' => 'oidc',
        'name' => str_repeat('x', 200), // max:120 → falha de validação
        'oidc_issuer' => SSO_ISSUER,
        'oidc_client_id' => SSO_CLIENT_ID,
        'oidc_client_secret' => SSO_CLIENT_SECRET,
    ])->assertSessionHasErrors('name');

    expect(session()->getOldInput('oidc_client_secret'))->toBeNull()
        ->and(serialize(session()->all()))->not->toContain(SSO_CLIENT_SECRET);
});

it('edição com erro de validação não guarda o client secret novo na sessão', function () {
    $connection = ssoOidcConnection($this->org);

    $this->patch(route('settings.sso.connections.update', $connection->ulid), [
        'jit_role' => 'owner', // fora da lista → falha de validação
        'oidc_client_secret' => 'segredo-novo-que-ninguem-pode-ver',
    ])->assertSessionHasErrors('jit_role');

    expect(serialize(session()->all()))->not->toContain('segredo-novo-que-ninguem-pode-ver')
        ->and(SsoConnection::withoutOrganizationScope()->find($connection->id)->oidc_client_secret)->toBe(SSO_CLIENT_SECRET);
});
