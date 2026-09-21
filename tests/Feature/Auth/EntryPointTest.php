<?php

use App\Models\User;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| A entrada do site é o login
|--------------------------------------------------------------------------
| Não há página institucional: `/` leva o visitante direto para o login (decisão do
| proprietário em 2026-09-21), e quem já está autenticado vai para onde o login o levaria.
| A rota `home` continua existindo porque logos, páginas de erro, logout e exclusão de conta
| apontam para ela.
*/

beforeEach(fn () => $this->withoutVite());

it('leva o visitante direto para o login', function () {
    $this->get('/')->assertRedirect(route('login'));

    $page = $this->followingRedirects()->get('/')->assertOk();

    expect($page->viewData('page')['component'])->toBe('auth/login');
});

it('leva quem já entrou para o painel da organização', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get('/')->assertRedirect(route('dashboard'));
});

it('leva o administrador da plataforma sem organização para o painel interno', function () {
    $this->actingAs(User::factory()->platformAdmin()->create());

    $this->get('/')->assertRedirect(route('admin.organizations.index'));
});
