<?php

use App\Support\OrganizationSettings;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de segurança — "Encerrar sessões após 12 h inativas"
|--------------------------------------------------------------------------
| Configurações › Geral e segurança traz quatro switches (DESIGN_SYSTEM §2.12, linha do card
| "Segurança"). Dois deles são explicitamente Fase 2 e chegam desabilitados no front
| (`sso_enabled`, `ip_allowlist_enabled`, com a prop `phase2`). Os outros dois são
| apresentados como políticas ATIVAS da conta:
|
|  - "Exigir autenticação em duas etapas" → aplicada pelo middleware EnforceTwoFactorForOrganization;
|  - "Encerrar sessões após 12 h inativas" → `settings.session_idle_hours = 12`, gravado por
|    GeneralController::updateSecurity e devolvido por OrganizationSettings::sessionIdleHours()...
|    e nunca lido por ninguém. Não existe middleware, listener ou job que compare a última
|    atividade da sessão com esse valor.
|
| ROUTES_AND_PAGES §7 (Q27) define o comportamento exigido: "Implementar como middleware que
| compara `last_activity` com o menor `session_idle_hours` entre as orgs do usuário que tenham
| a política ativa."
|
| Enquanto isso não existe, a organização acredita ter uma política de expiração por
| inatividade que não expira nada.
*/

beforeEach(fn () => $this->withoutVite());

test('com a política ativa, a sessão parada por mais de 12 h é encerrada', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    OrganizationSettings::of($organization)->put(['session_idle_hours' => 12]);

    actingAsMember($owner, $organization);
    $this->get(route('dashboard'))->assertOk();

    $this->travel(13)->hours();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('com a política ativa, a sessão usada dentro da janela continua válida', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    OrganizationSettings::of($organization)->put(['session_idle_hours' => 12]);

    actingAsMember($owner, $organization);
    $this->get(route('dashboard'))->assertOk();

    $this->travel(11)->hours();

    $this->get(route('dashboard'))->assertOk();
});

test('sem a política ativa, a sessão não é encerrada por inatividade', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    expect(OrganizationSettings::of($organization)->sessionIdleHours())->toBeNull();

    actingAsMember($owner, $organization);

    $this->travel(30)->hours();

    $this->get(route('dashboard'))->assertOk();
});
