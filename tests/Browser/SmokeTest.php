<?php

use App\Enums\FieldType;

require_once __DIR__.'/Support/BrowserHelpers.php';
require_once __DIR__.'/../Feature/Support/OrganizationHelpers.php';
require_once __DIR__.'/../Feature/Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Varredura das telas principais
|--------------------------------------------------------------------------
| Uma volta rápida por todas as telas que o produto entrega, verificando o que
| um teste HTTP não vê: que o React monta sem erro de JavaScript e que nada em
| inglês escapou para a interface.
|
| `/busca` e `/notificacoes` ficam de fora da lista: são endpoints JSON
| consumidos pela paleta ⌘K e pelo sino, não telas — quem as cobre é
| tests/Feature.
|
| É o teste que quebra quando um componente novo é importado errado, quando uma
| tradução some ou quando uma tela de erro do Laravel aparece no lugar da
| aplicação — falhas que o `assertOk()` de um teste de rota não pega, porque o
| HTML chega íntegro e é o navegador que engasga depois.
*/

beforeEach(function () {
    $this->work = browserWorkspace();
    browserDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
});

afterEach(function () {
    browserCleanup($this->work ?? null);
});

it('abre as telas públicas sem erro de JavaScript e sem inglês', function (string $path, string $expected) {
    $page = visit($path);

    $page->assertSee($expected)
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, $path);
})->with([
    'início (vai direto para o login)' => ['/', 'Acesse sua conta AssinaVelox.'],
    'termos' => ['/termos', 'Termos de uso'],
    'privacidade' => ['/privacidade', 'Política de Privacidade'],
    'verificação' => ['/verificar', 'Verificar documento'],
    'login' => ['/login', 'Acesse sua conta AssinaVelox.'],
    'cadastro' => ['/register', 'Criar conta'],
    'esqueci a senha' => ['/forgot-password', 'senha'],
]);

it('abre as telas autenticadas sem erro de JavaScript e sem inglês', function (string $path, string $expected) {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);
    $owner->forceFill(['password' => bcrypt(browserPassword())])->save();

    $page = visit('/login');
    browserLogin($page, $owner->fresh()->email);
    $page->assertPathIs('/dashboard');

    $page->navigate($path);

    $page->assertSee($expected)
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, $path);
})->with([
    'painel' => ['/dashboard', 'Horizonte Consultoria'],
    'documentos' => ['/documentos', 'Documentos'],
    'assinaturas' => ['/assinaturas', 'Assinaturas'],
    'usuários' => ['/usuarios', 'Usuários'],
    'modelos (fase 2)' => ['/modelos', 'Modelos'],
    'configurações' => ['/configuracoes', 'Configurações'],
    'assinatura da operadora' => ['/configuracoes/assinatura', 'assinatura'],
    'plano e cobrança' => ['/configuracoes/plano', 'Plano'],
    'planos' => ['/planos', 'Plano'],
    'notificações (preferências)' => ['/configuracoes/notificacoes', 'Notificações'],
    'perfil' => ['/perfil', 'Perfil'],
]);

it('abre o detalhe de um documento enviado sem erro de JavaScript', function () {
    $scenario = signerEnvelope([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]],
    ]);

    $owner = $scenario['owner'];
    $owner->forceFill(['password' => bcrypt(browserPassword())])->save();

    $page = visit('/login');
    browserLogin($page, $owner->fresh()->email);
    $page->assertPathIs('/dashboard');

    $page->navigate('/documentos/'.$scenario['envelope']->ulid);

    $page->assertSee('Contrato de locação')
        ->assertSee('Maria Alves Souza')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'detalhe do documento');

    $page->navigate('/documentos/'.$scenario['envelope']->ulid.'/evidencias');

    $page->assertSee('Evidências')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'evidências');
});

it('responde em português a um documento inexistente', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $owner->forceFill(['password' => bcrypt(browserPassword())])->save();

    $page = visit('/login');
    browserLogin($page, $owner->fresh()->email);
    $page->assertPathIs('/dashboard');

    $page->navigate('/documentos/01ARZ3NDEKTSV4RRFFQ69G5FAV');

    // Em ambiente de teste esta tela é a do framework, não a do produto — ver o
    // `skip` logo abaixo. O que dá para afirmar aqui é que ela sai em PT-BR e
    // sem detalhe interno: a mensagem do Laravel para binding que falha é
    // "No query results for model [App\Models\Envelope] 01ARZ…", e ela não
    // aparece.
    $page->assertSee('Página não encontrada')
        ->assertDontSee('No query results for model')
        ->assertDontSee('Not Found')
        ->assertNoJavascriptErrors();
});

it('mostra a página de erro do produto (errors/404.tsx) para um documento inexistente', function () {
    // Sem corpo de propósito — ver a mensagem do skip.
})->skip(
    'As páginas de erro do produto (resources/js/pages/errors/{403,404,500}.tsx) são '
    .'INALCANÇÁVEIS em teste automatizado, inclusive no navegador. '
    .'`bootstrap/app.php:136` devolve a resposta crua quando `app()->runningUnitTests()` é '
    .'verdadeiro — decisão deliberada e comentada, para que os testes HTTP possam afirmar '
    .'status e mensagem originais. O efeito colateral é que o navegador recebe a página de '
    .'erro do Laravel ("404 / Página não encontrada"), e não o componente Inertia com os '
    .'botões "Página inicial" e "Ir para o painel". Não é defeito: é uma escolha de projeto '
    .'com um ponto cego. Para fechá-lo seria preciso uma chave de configuração que ligasse '
    .'a renderização Inertia dos erros só na suíte de navegador — decisão de quem cuida de '
    .'bootstrap/app.php, fora da área do T-BROWSER.'
);
