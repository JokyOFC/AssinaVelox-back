<?php

require_once __DIR__.'/Support/BrowserHelpers.php';
require_once __DIR__.'/../Feature/Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Autenticação no navegador
|--------------------------------------------------------------------------
| Cobre o que o teste HTTP não consegue afirmar: que o formulário React monta,
| envia pelo Inertia e leva ao painel; que o erro do servidor aparece na tela em
| PT-BR; e que sair encerra a sessão de verdade (o navegador com os cookies que
| tem em mãos deixa de acessar área autenticada).
*/

beforeEach(function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);

    $owner->forceFill(['password' => bcrypt(browserPassword())])->save();

    $this->organization = $organization;
    $this->owner = $owner->fresh();
});

it('entra com credenciais válidas e chega ao painel', function () {
    $page = visit('/login');

    browserLogin($page, $this->owner->email);

    $page->assertPathIs('/dashboard')
        ->assertSee('Horizonte Consultoria')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'painel');
});

it('mostra erro em português quando a senha está errada', function () {
    $page = visit('/login');

    browserLogin($page, $this->owner->email, 'senha-errada');

    $page->assertPathIs('/login')
        ->assertSee('Estas credenciais não correspondem aos nossos registros.')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'login com erro');
});

it('mostra erro em português quando o e-mail não existe', function () {
    $page = visit('/login');

    browserLogin($page, 'ninguem@exemplo.test');

    $page->assertPathIs('/login')
        ->assertSee('Estas credenciais não correspondem aos nossos registros.')
        ->assertNoJavascriptErrors();
});

it('sai da conta e perde o acesso à área autenticada', function () {
    $page = visit('/login');

    browserLogin($page, $this->owner->email);
    $page->assertPathIs('/dashboard');

    $page->click('[data-test=sidebar-menu-button]')
        ->click('[data-test=logout-button]');

    // Sair leva à página pública inicial; o que interessa é que o painel deixou
    // de ser acessível com os cookies que o navegador continua tendo.
    $page->assertPathIsNot('/dashboard');

    $page->navigate('/dashboard');

    $page->assertPathIs('/login')
        ->assertNoJavascriptErrors();
});
