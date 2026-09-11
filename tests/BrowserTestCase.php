<?php

namespace Tests;

use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Vite;
use Pest\Browser\Playwright\Playwright;

/**
 * Caso base da suíte `Browser`.
 *
 * O `pestphp/pest-plugin-browser` 4.x **não** sobe um processo separado: o
 * `Pest\Browser\Drivers\LaravelHttpServer` é um servidor Amp que roda dentro do
 * mesmo processo PHP do teste e entrega cada requisição ao `HttpKernel` da
 * aplicação que o teste já tem em mãos. Consequências:
 *
 * - o banco `:memory:` do `phpunit.xml` **é** compartilhado com o servidor, e a
 *   transação do `RefreshDatabase` continua valendo — nenhum banco de arquivo é
 *   necessário (ver docs/testes.md §"Banco de cada suíte");
 * - o que **não** é compartilhado é a sessão HTTP: o `SESSION_DRIVER=array` do
 *   `phpunit.xml` guarda a sessão em memória e a descarta ao fim de cada
 *   requisição, então o navegador manda o cookie e recebe uma sessão vazia.
 *
 * Sem sessão persistente nada que dependa de estado entre requisições funciona:
 * a organização corrente (`EnsureCurrentOrganization::SESSION_KEY`), a sessão
 * curta do signatário (`SignerSessions`, criada na verificação do código e lida
 * na tela de assinatura) e as mensagens flash. Por isso a suíte troca o driver
 * para `database`: a tabela `sessions` vive no mesmo banco em memória, é vista
 * pelo servidor e pelo teste, e o `RefreshDatabase` a limpa entre testes — sem
 * deixar arquivo nenhum para trás.
 */
abstract class BrowserTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
        config()->set('session.connection', config('database.default'));

        // O gerenciador pode já ter criado o driver `array` durante o boot.
        Session::forgetDrivers();

        // Os testes de navegador exercitam o build de produção (public/build). Com um
        // `public/hot` presente — de um `npm run dev` aberto ou, pior, órfão depois que o
        // servidor foi encerrado — o helper do Vite passa a apontar para o servidor de
        // desenvolvimento: as páginas carregam sem JavaScript e cada asserção espera o
        // teto de 20 s abaixo, o que se parece com um travamento da suíte. Apontar o hot
        // file para um caminho inexistente isola a suíte disso sem apagar o arquivo de
        // quem está desenvolvendo (mesma técnica de tests/Feature/Smoke/CspAndBuildAssetsTest).
        Vite::useHotFile(storage_path('framework/testing/vite-hot-disabled'));

        // O padrão do plugin (5 s) é curto para as telas pesadas desta
        // aplicação: o editor de campos e a página do signatário só ficam
        // prontos depois de o PDF.js baixar o arquivo, criar o worker e
        // rasterizar a primeira página. Toda asserção do plugin repete até
        // este limite, então subir o teto torna a suíte mais estável sem
        // introduzir espera fixa nenhuma — um teste que passa continua
        // passando na mesma velocidade.
        Playwright::setTimeout(20_000);
    }
}
