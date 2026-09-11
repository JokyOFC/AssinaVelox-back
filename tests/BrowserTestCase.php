<?php

namespace Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Vite;
use Pest\Browser\Playwright\Playwright;
use Pest\Browser\ServerManager;
use Pest\Plugins\Parallel;
use ReflectionProperty;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

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
 *
 * ## Falhar rápido em vez de travar (integração I-2C, docs/testes.md §6-C)
 *
 * O cliente do plugin (`Pest\Browser\Playwright\Client::execute`) espera a resposta do
 * Playwright num `while (true)` sem teto próprio: se a resposta nunca chega, o teste espera
 * para sempre; se o WebSocket FECHA (servidor do Playwright ou navegador morreu), `receive()`
 * devolve `null` na hora e o laço vira espera ativa infinita a 100% de CPU. O mesmo vale para
 * a partida do servidor do Playwright (`waitUntil` sem teto). Duas camadas, sem tocar no vendor:
 *
 * 1. **Vigia por teste** ({@see self::BROWSER_TEST_TIMEOUT}): um temporizador do laço de
 *    eventos (Revolt) que, se o teste passar do teto enquanto espera o navegador, interrompe a
 *    espera com uma exceção que diz qual teste e por quê — o teste FALHA e a suíte segue.
 * 2. **Teto de processo** (`set_time_limit`, renovado a cada teste): cobre a espera ativa, em
 *    que o laço de eventos nunca roda e o vigia não dispara. No Windows o teto conta tempo de
 *    relógio; no Linux, tempo de CPU — que é justamente o que a espera ativa consome. Estourado,
 *    o PHP encerra o processo com erro fatal e o gancho de desligamento diz qual teste era.
 *
 * Toda asserção e toda navegação do plugin já têm teto próprio: o `timeout` abaixo é enviado
 * ao Playwright em cada chamada (`goto`, cliques, esperas), então nenhuma navegação passa dele.
 */
abstract class BrowserTestCase extends TestCase
{
    /** Teto de um teste inteiro (segundos). A suíte toda passa em ~45 s; o mais lento, em ~10 s. */
    public const BROWSER_TEST_TIMEOUT = 90;

    /** Folga do teto de processo sobre o vigia (segundos). */
    public const PROCESS_LIMIT_MARGIN = 30;

    /** Teto para a limpeza entre testes e para o encerramento do plugin (segundos). */
    public const TEARDOWN_LIMIT = 120;

    private ?string $watchdogId = null;

    private static bool $shutdownHookRegistered = false;

    private static ?string $currentTest = null;

    /** Guarda externa contra o servidor do Playwright órfão (mantida viva até o fim do processo). */
    private static ?Process $guard = null;

    protected function setUp(): void
    {
        self::$currentTest = static::class.'::'.$this->name();
        $this->armProcessLimit();

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
        // passando na mesma velocidade. É também o teto de cada navegação.
        Playwright::setTimeout(20_000);

        self::guardPlaywrightServer();
        $this->armWatchdog();
    }

    /**
     * Dispara, uma vez por processo, a guarda externa contra servidor do Playwright órfão
     * (tests/Browser/Support/playwright-orphan-guard.php). O plugin sobe o servidor ao carregar
     * a suíte (`UsesBrowserTestCaseMethodFilter`), então no primeiro setUp o PID já existe. O PID
     * é lido por reflexão de `PlaywrightNpmServer::$systemProcess` (interno do plugin): se a
     * estrutura mudar numa atualização, a guarda simplesmente não é disparada.
     */
    private static function guardPlaywrightServer(): void
    {
        if (self::$guard !== null || Parallel::isWorker()) {
            return;
        }

        try {
            $server = ServerManager::instance()->playwright();
            $property = new ReflectionProperty($server, 'systemProcess');
            $process = $property->getValue($server);
        } catch (Throwable) {
            return;
        }

        if (! $process instanceof Process || ! $process->isRunning() || $process->getPid() === null) {
            return;
        }

        $guard = new Process([PHP_BINARY, __DIR__.'/Browser/Support/playwright-orphan-guard.php', (string) getmypid(), (string) $process->getPid()]);
        // Console própria no Windows: a guarda sobrevive ao processo de teste (é para isso que existe).
        $guard->setOptions(['create_new_console' => true]);
        $guard->disableOutput();
        $guard->setTimeout(null);
        $guard->start();

        self::$guard = $guard;
    }

    protected function tearDown(): void
    {
        // A limpeza do plugin (flush do servidor HTTP e reset dos contextos do navegador) roda
        // antes, nos `afterEach`. Daqui para frente só há limpeza: teto próprio, vigia desarmado.
        $this->disarmWatchdog();
        set_time_limit(self::TEARDOWN_LIMIT);

        // Relógio congelado por um teste não pode vazar para o seguinte.
        Carbon::setTestNow();

        parent::tearDown();

        self::$currentTest = null;
    }

    private function armWatchdog(): void
    {
        $test = (string) self::$currentTest;
        $seconds = self::timeout();
        $startedAt = microtime(true);

        $this->watchdogId = EventLoop::unreference(EventLoop::delay($seconds, static function () use ($test, $seconds, $startedAt): void {
            $message = sprintf(
                'Teste de navegador passou do teto de %d s (%.1f s decorridos) esperando o navegador: %s. '
                .'A espera foi interrompida para a suíte falhar em vez de travar (ver docs/testes.md §6-C).',
                $seconds,
                microtime(true) - $startedAt,
                $test,
            );

            fwrite(STDERR, PHP_EOL.'[navegador] '.$message.PHP_EOL);

            throw new RuntimeException($message);
        }));
    }

    private function disarmWatchdog(): void
    {
        if ($this->watchdogId !== null) {
            EventLoop::cancel($this->watchdogId);
            $this->watchdogId = null;
        }
    }

    private function armProcessLimit(): void
    {
        set_time_limit(self::timeout() + self::PROCESS_LIMIT_MARGIN);

        if (self::$shutdownHookRegistered) {
            return;
        }

        self::$shutdownHookRegistered = true;

        // Registrado no primeiro setUp — antes de o laço de eventos existir —, então roda antes do
        // desligamento do Revolt. Numa saída anormal (teto de tempo, erro fatal, memória) o
        // `Plugin::terminate()` do plugin NUNCA roda: o servidor do Playwright (cmd → node →
        // navegador) ficaria órfão, segurando o stdout herdado, e quem esperava a saída do
        // processo — o terminal, um script encadeado, o CI — pareceria travado para sempre
        // (foi o que a sonda da integração I-2C reproduziu: 495 s até matar o órfão à mão).
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            $fatal = $error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);

            if ($fatal) {
                fwrite(STDERR, PHP_EOL.sprintf(
                    '[navegador] Processo encerrado durante %s: %s. Encerrando o servidor do Playwright '
                    .'para não deixar processo órfão (ver docs/testes.md §6-C).',
                    self::$currentTest ?? 'a limpeza/encerramento da suíte',
                    (string) $error['message'],
                ).PHP_EOL);
            }

            // Numa saída normal o plugin já parou o servidor e isto não faz nada.
            try {
                if (! Parallel::isWorker()) {
                    ServerManager::instance()->playwright()->stop();
                }
            } catch (Throwable) {
                // Desligamento: nada a fazer além de tentar.
            }
        });
    }

    private static function timeout(): int
    {
        $configured = getenv('BROWSER_TEST_TIMEOUT');

        return is_string($configured) && ctype_digit($configured) && (int) $configured > 0
            ? (int) $configured
            : self::BROWSER_TEST_TIMEOUT;
    }
}
