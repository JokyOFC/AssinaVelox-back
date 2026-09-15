<?php

use App\Enums\MembershipRole;
use App\Services\Sso\SsoConnectionStatus;
use App\Services\Sso\SsoSession;

require_once __DIR__.'/../../Phase3/Sso/Support/SsoHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial onda G (produto/semântica) — login corporativo obrigatório
|--------------------------------------------------------------------------
| 1. Configurações › Login único, cartão "Login corporativo obrigatório": para o ADMIN a tela
|    diz "Só o proprietário da conta liga ou desliga esta opção.", mas o mesmo admin tem os
|    botões "Desativar" e "Remover conexão" habilitados — e SsoConnectionManager::setStatus
|    derruba `enforce` quando o admin desativa ("Desligar nunca tranca"). A regra é defensável;
|    a frase é falsa e esconde do admin a consequência do botão ao lado.
|
| 2. O owner SEM 2FA liga a obrigatoriedade sem aviso nem recusa. A própria tela promete que
|    "Proprietários mantêm o acesso de emergência por senha com autenticação em duas etapas" e,
|    na requisição seguinte, o middleware o manda para Segurança da conta. Quem liga a chave
|    não fica sabendo que acabou de perder o acesso por senha.
|
| 3. Tela de login: os campos de senha usam tabIndex positivo (1–6) e o "Entrar com SSO" não
|    tem nenhum, então pelo teclado ele só é alcançado depois de "Criar conta grátis" e dos
|    links do painel lateral, fora da ordem visual.
*/

beforeEach(fn () => $this->withoutVite());

it('o texto do cartão de obrigatoriedade para o admin não esconde que "Desativar" derruba a exigência', function () {
    ['organization' => $organization] = ssoWorkspace();
    $connection = ssoOidcConnection($organization, ['enforce' => true]);
    $admin = attachMember($organization, MembershipRole::Admin);

    actingAsMember($admin, $organization);
    // Com a obrigatoriedade ligada, o admin só alcança a organização pela sessão aberta pelo SSO
    // dela (docs/fase-3/sso.md §5; mesmo preparo de EnforcementTest › "o admin pode desativar").
    // Sem isto o POST é desviado para /sso/obrigatorio e a premissa do teste nunca roda.
    session()->put(SsoSession::AUTHENTICATED, [(string) $organization->id => $connection->id]);

    // Comportamento real: o admin desativa a conexão e a obrigatoriedade cai junto.
    $this->post(route('settings.sso.connections.status', $connection->ulid), ['status' => SsoConnectionStatus::Disabled->value])
        ->assertRedirect();

    expect($connection->fresh()->enforce)->toBeFalse();

    $source = (string) file_get_contents(base_path('resources/js/pages/settings/sso.tsx'));

    // Uma agulha só: em `not->toContain(a, b)` o segundo argumento é OUTRA agulha, não a mensagem
    // (tests/Feature/Review/VacuousNegativeAssertionTest proíbe a forma ambígua).
    expect(str_contains($source, 'Só o proprietário da conta liga ou desliga esta opção.'))->toBeFalse(
        'A tela diz ao admin que só o proprietário desliga a obrigatoriedade, mas o admin a derruba com "Desativar" (SsoConnectionManager::setStatus).',
    );
});

it('owner sem autenticação em duas etapas não liga o login corporativo obrigatório às cegas', function () {
    ['organization' => $organization, 'owner' => $owner] = ssoWorkspace();
    $connection = ssoOidcConnection($organization);

    expect($owner->hasTwoFactorEnabled())->toBeFalse();

    actingAsMember($owner, $organization);

    $response = $this->patch(route('settings.sso.connections.update', $connection->ulid), ['enforce' => true]);

    // Hoje: aceito com "Alterações salvas." e, na próxima tela, o owner é mandado para Segurança.
    $response->assertSessionHasErrors(
        'enforce',
        'O owner sem 2FA ligou a obrigatoriedade sem aviso; o acesso de emergência que a tela promete não existe para ele.',
    );
});

it('pelo teclado, "Entrar com SSO" vem logo depois do botão Entrar, não depois de "Criar conta grátis"', function () {
    $login = (string) file_get_contents(base_path('resources/js/pages/auth/login.tsx'));
    $entry = (string) file_get_contents(base_path('resources/js/components/sso/sso-login-entry.tsx'));

    expect($login)->toMatch('/tabIndex=\{[1-9]\}/', 'A tela de login deixou de usar tabIndex positivo; reavalie o achado.');

    expect(preg_match('/tabIndex=\{[1-9]\}/', $entry))->toBe(
        1,
        'Login usa tabIndex 1–6 e o "Entrar com SSO" não tem nenhum: no Tab ele fica depois de "Criar conta grátis" e dos links do painel lateral.',
    );
});
