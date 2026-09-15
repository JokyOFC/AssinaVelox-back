<?php

use App\Models\AuditEvent;

require_once __DIR__.'/Support/SsoReviewHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão G — `trust_idp` de UMA organização dispensa o 2FA da CONTA inteira
|--------------------------------------------------------------------------
| docs/fase-3/sso.md §8.1: "`trust_idp`: a ORGANIZAÇÃO decide confiar no segundo fator do
| próprio provedor". Mas SsoLoginCompleter::login() faz Auth::login() global: a sessão aberta
| sem o 2FA do usuário vale também nas OUTRAS organizações dele (troca por
| `organizations.switch`), inclusive onde ele é owner, e satisfaz o break-glass de uma
| organização com SSO obrigatório — que só confere `hasTwoFactorEnabled()`, não que o código
| tenha sido digitado nesta sessão (SsoEnforcement::evaluate).
*/

beforeEach(function () {
    reviewG3FakeProviders($this);
});

it('a confiança no MFA do IdP da organização A não dispensa o 2FA do usuário na organização B, onde ele é owner', function () {
    ['organization' => $orgA] = ssoWorkspace();
    $connection = ssoOidcConnection($orgA, ['two_factor_policy' => 'trust_idp']);
    $user = ssoMember($orgA); // maria@empresa.com.br, membro comum em A
    reviewG3EnableTwoFactor($user);
    ['organization' => $orgB] = createOrganizationWithOwner(['name' => 'Empresa Pessoal da Maria'], $user);

    reviewG3OidcLogin($this, $connection, $user->email)->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($user);
    expect(ssoLastAudit($orgA, 'sso.login_succeeded')['two_factor'] ?? null)->toBe('trusted_idp');

    $this->post(route('organizations.switch', $orgB));
    $status = $this->get(route('dashboard'))->status();

    // Hoje: 200 — o painel da organização B (owner) abre sem o 2FA que a Maria ativou na conta.
    expect($status)->not->toBe(200);
});

it('o break-glass do owner aceita sessão em que o 2FA nunca foi digitado (veio do trust_idp de outra organização)', function () {
    ['organization' => $trusting] = ssoWorkspace();
    $connection = ssoOidcConnection($trusting, ['two_factor_policy' => 'trust_idp']);
    $user = ssoMember($trusting);
    reviewG3EnableTwoFactor($user);

    ['organization' => $enforced] = createOrganizationWithOwner(['name' => 'Exige Login Corporativo'], $user);
    ssoEnable($enforced);
    ssoOidcConnection($enforced, ['enforce' => true]);

    reviewG3OidcLogin($this, $connection, $user->email)->assertRedirect(config('fortify.home'));

    $this->post(route('organizations.switch', $enforced));
    $status = $this->get(route('dashboard'))->status();

    $breakGlass = AuditEvent::withoutOrganizationScope()
        ->where('organization_id', $enforced->id)
        ->where('event_type', 'sso.break_glass_used')
        ->first();

    // Hoje: 200 e a trilha grava `two_factor: true` — "senha + 2FA" sem senha e sem 2FA.
    expect($status)->not->toBe(200)
        ->and($breakGlass)->toBeNull();
});
