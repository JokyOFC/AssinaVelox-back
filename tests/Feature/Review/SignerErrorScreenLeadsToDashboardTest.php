<?php

use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final (experiência) — o signatário sem conta é mandado para "o painel"
|--------------------------------------------------------------------------
| `EnsureSignerVerified` aborta com 404 quando a sessão de assinatura (30 min) acabou.
| O 404 em si é a decisão certa e já está coberto por testes de segurança — o problema é
| a TELA que o signatário recebe.
|
| O docblock do próprio middleware promete outra coisa
| (app/Http/Middleware/EnsureSignerVerified.php:22-24):
|
|   "Requisições de navegação voltam para `sign.show`, onde a pessoa recebe a etapa
|    'Confirmar identidade'."
|
| O código faz o contrário: só o ramo não-GET redireciona, com a mensagem "Sua sessão
| expirou. Confirme o código enviado por e-mail para continuar." (linha 56). O GET — que
| é justamente a navegação — cai em `abort(404, 'Documento indisponível.')` (linha 51), e
| `bootstrap/app.php:142` mapeia 404 para `resources/js/pages/errors/404.tsx`.
|
| Essa tela não olha para `auth.user`. Oferece sempre:
|
|     [Página inicial]   [Ir para o painel]        ← ação primária, href = /dashboard
|
| e `errors/403.tsx` oferece só "Voltar ao painel", com o texto "Peça a um administrador
| da organização para liberar o acesso". Um signatário não tem painel, não tem conta e não
| tem administrador de organização: o clique leva a /dashboard, que redireciona ao login,
| onde ele não tem credenciais.
|
| Verificado no navegador (375 px, sessão de assinatura vencida): título "Página não
| encontrada", descrição "Documento indisponível.", botões "Página inicial" e "Ir para o
| painel". Nenhuma menção ao prazo da sessão e nenhum caminho de volta para
| /assinar/{token}, que é o único endereço que o signatário tem.
|
| O caso não é raro: a sessão dura 30 minutos e o signatário costuma abrir o e-mail, pedir
| o código, ler o contrato com calma e voltar.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-signer-404-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

it('a rota pública do signatário sem sessão cai na tela de erro genérica do painel', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];

    // Sem sessão: exatamente o estado de quem volta depois de 30 min.
    $this->get(route('sign.document', ['token' => $token]))->assertNotFound();

    // O 404 é servido pelo componente genérico (bootstrap/app.php:142) …
    $errorPages = (string) file_get_contents(base_path('bootstrap/app.php'));
    expect($errorPages)->toContain("404 => 'errors/404'");

    // … cuja única ação primária manda o visitante público para /dashboard.
    $notFound = (string) file_get_contents(resource_path('js/pages/errors/404.tsx'));

    expect($notFound)->not->toContain('Ir para o painel');
});

it('a tela de acesso negado também só oferece o painel a quem não tem conta', function () {
    $forbidden = (string) file_get_contents(resource_path('js/pages/errors/403.tsx'));

    expect($forbidden)->not->toContain('Voltar ao painel');
});

/*
| AJUSTE DE TESTE (revisão final).
|
| O caso original mandava um GET simples a `sign.document` e exigia REDIRECIONAMENTO — mas o
| primeiro caso deste mesmo arquivo exige 404 para o mesmo GET, e o próprio achado diz que o
| 404 está certo ("mantendo o 404 apenas para o streaming de bytes"). As duas asserções não
| podiam valer juntas: `sign.document` é a rota que TRANSMITE o PDF, e `sign.page` é a
| miniatura descontinuada — não há, atrás deste middleware, nenhum GET que seja "tela".
|
| O que foi corrigido é o caso real que o achado descreve: a pessoa que volta depois dos 30
| minutos com a aba aberta no endereço do PDF. Essa requisição é uma NAVEGAÇÃO de primeiro
| nível e o navegador a identifica com `Sec-Fetch-Mode: navigate` + `Sec-Fetch-Dest:
| document` — cabeçalhos que um `fetch()` do visualizador, um `<embed>` ou um cliente
| qualquer não mandam. Com eles, volta para `sign.show` com o aviso de sessão expirada; sem
| eles, continua 404. O padrão continua sendo o seguro.
*/
it('a navegação de primeiro nível volta para sign.show em vez de cair no 404', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];

    $middleware = (string) file_get_contents(app_path('Http/Middleware/EnsureSignerVerified.php'));

    // A promessa está escrita no arquivo …
    expect($middleware)->toContain('voltam para');

    // … e agora o navegador que volta ao endereço do PDF é levado de volta ao fluxo.
    $this->withHeaders(['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'])
        ->get(route('sign.document', ['token' => $token]))
        ->assertRedirect(route('sign.show', ['token' => $token]));

    // O que não é navegação (o próprio visualizador buscando os bytes) continua em 404.
    $this->withHeaders(['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'])
        ->get(route('sign.document', ['token' => $token]))
        ->assertNotFound();
});
