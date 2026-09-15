<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\EmbeddedSigningSession;
use App\Models\SignatureAcceptance;
use App\Services\Embed\AllowedOrigins;
use App\Services\Embed\EmbeddedSessionIssuer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pest\Browser\ServerManager;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/Support/BrowserHelpers.php';
require_once __DIR__.'/../Feature/Support/OrganizationHelpers.php';
require_once __DIR__.'/../Feature/Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../Feature/Phase3/Embed/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| Widget de assinatura embutida no navegador (G-EMBED, docs/fase-3/widget-embutido.md §10)
|--------------------------------------------------------------------------
| O servidor de teste responde em 127.0.0.1 e em localhost — duas ORIGENS diferentes para o
| navegador. O app (e o widget) fica em http://127.0.0.1:PORTA; o "site do cliente" é uma página
| de teste servida em http://localhost:PORTA, fora da CSP do app (é o site de outra empresa).
|
| - origem permitida: o iframe carrega e o site recebe `assinavelox:ready`;
| - origem não permitida: a CSP `frame-ancestors` bloqueia o iframe — nenhuma troca acontece e
|   o embed.js avisa `timeout`;
| - mensagens forjadas (da própria página e de outro iframe da MESMA origem do widget) são
|   ignoradas nos dois sentidos; o `ping` legítimo do site é respondido.
*/

beforeEach(function () {
    $this->work = browserWorkspace();
    browserDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
    $this->notifications = browserCaptureNotifications();

    // As páginas `/_teste/*` fazem o papel do SITE DO CLIENTE: ficam fora da CSP do app (que
    // proíbe iframe e script inline). Todo o resto continua com o middleware real.
    app()->bind(SecurityHeaders::class, fn () => new class extends SecurityHeaders
    {
        public function handle(Request $request, Closure $next): Response
        {
            return $request->is('_teste/*') ? $next($request) : parent::handle($request, $next);
        }
    });

    // O servidor HTTP do plugin sobe no primeiro `visit()`; aqui ele é iniciado antes, porque a
    // origem (com a porta) entra na lista de origens e na sessão criada antes da visita.
    ServerManager::instance()->http()->bootstrap();
    $this->appOrigin = rtrim(ServerManager::instance()->http()->rewrite('/'), '/');
    $this->hostOrigin = str_replace('://127.0.0.1', '://localhost', $this->appOrigin);
});

afterEach(function () {
    browserCleanup($this->work ?? null);
});

/**
 * Sessão embutida real (serviço da API) para a origem dada, com a URL no endereço do servidor.
 *
 * @param  list<string>  $allowed
 * @return array{session: EmbeddedSigningSession, url: string}
 */
function embedBrowserSession(object $test, string $origin, array $allowed): array
{
    $scenario = signerEnvelope();
    embedEnable($scenario['organization']);
    AllowedOrigins::replace($scenario['organization'], $allowed);

    $recipient = array_values($scenario['recipients'])[0];
    $issued = app(EmbeddedSessionIssuer::class)->issue($scenario['envelope'], $recipient, $origin, null, null, $scenario['owner']);

    return [
        'session' => $issued['session'],
        'url' => $test->appOrigin.'/embed/v1/sessoes/'.$issued['session']->ulid.'#t='.embedTokenFrom($issued['url']),
    ];
}

/** Página do site do cliente: carrega o embed.js e registra os eventos recebidos. */
function embedHostRoute(string $url): void
{
    $json = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    Route::get('/_teste/embed-host', fn () => response(<<<HTML
<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><title>Portal do cliente</title></head>
<body>
<h1>Portal do cliente</h1>
<div id="widget" style="width: 900px"></div>
<ul id="events"></ul>
<script src="/embed/v1/embed.js"></script>
<script>
window.__events = [];
function log(kind, detail) {
    window.__events.push({ kind: kind, detail: detail });
    var item = document.createElement('li');
    item.textContent = kind + ':' + (detail.screen || detail.code || detail.status || '');
    document.getElementById('events').appendChild(item);
}
window.__widget = AssinaVelox.mount({
    url: {$json},
    container: '#widget',
    readyTimeout: 6000,
    onReady: function (d) { log('ready', d); },
    onCompleted: function (d) { log('completed', d); },
    onRefused: function (d) { log('refused', d); },
    onError: function (d) { log('error', d); }
});
</script>
</body>
</html>
HTML));
}

/** @return list<string> */
function embedEventKinds(object $page): array
{
    $kinds = $page->script('window.__events.map(function (e) { return e.kind; })');

    return is_array($kinds) ? array_values($kinds) : [];
}

it('página hospedeira da origem permitida carrega o widget e recebe assinavelox:ready', function () {
    $embed = embedBrowserSession($this, $this->hostOrigin, [$this->hostOrigin]);
    embedHostRoute($embed['url']);

    $page = visit($this->hostOrigin.'/_teste/embed-host');

    $page->assertSee('ready:identify');

    $session = browserWaitFor(
        fn () => EmbeddedSigningSession::withoutGlobalScopes()->whereKey($embed['session']->id)->whereNotNull('used_at')->first(),
        'a troca da URL de uso único',
    );

    expect($session->runtime_token_digest)->not->toBeNull()
        ->and(embedEventKinds($page))->toBe(['ready']);

    $token = embedTokenFrom($embed['url']);

    $page->withinFrame('iframe', function ($frame) use ($token): void {
        $frame->assertSee('Confirme o código para abrir o documento')
            ->assertSee('Receber código por e-mail');

        // O token de uso único saiu do endereço do iframe e não ficou no histórico.
        expect($frame->script('window.location.hash'))->toBe('')
            ->and((string) $frame->script('JSON.stringify(window.history.state || null)'))->not->toContain($token)
            ->and((string) $frame->script('window.location.href'))->not->toContain($token);
    });
});

it('assina dentro do widget: código, assinatura, confirmação visual e aviso ao site', function () {
    $embed = embedBrowserSession($this, $this->hostOrigin, [$this->hostOrigin]);
    embedHostRoute($embed['url']);
    $codes = $this->notifications['codes'];

    $page = visit($this->hostOrigin.'/_teste/embed-host');

    $page->assertSee('ready:identify');

    // Ações pela página do site, com o seletor que entra no iframe: o clique é do Playwright
    // (evento confiável, com rolagem e checagem de visibilidade), como o de uma pessoa.
    $inFrame = 'iframe >> internal:control=enter-frame >> ';

    $page->click($inFrame.'internal:role=button[name="Receber código por e-mail"s]');

    browserWaitFor(fn () => count($codes) > 0, 'o código ser enviado');

    $page->type($inFrame.'input[aria-label^="Código de "]', $codes[count($codes) - 1]);

    $page->withinFrame('iframe', function ($frame): void {
        $frame->assertSee('Sua assinatura')->assertSee('Código confirmado');

        browserDrawOnCanvas($frame, "document.querySelector('canvas[aria-label=\"Quadro para desenhar a assinatura\"]')");

        $frame->assertSee('Pronta');
    });

    $page->click($inFrame.'button[role=checkbox]');

    // O PDF chegou pelo cabeçalho (blob:) e tudo foi preenchido: o clique espera o botão habilitar.
    $page->click($inFrame.'internal:role=button[name="Assinar documento"s]');

    // Confirmação visual: o botão final só habilita com visibilidade REAL (IntersectionObserver
    // v2 no Chromium) e depois do intervalo mínimo; o clique do Playwright espera por isso.
    $page->click($inFrame.'internal:role=button[name="Confirmar e assinar"s]');

    $page->assertSee('completed:');

    $acceptance = browserWaitFor(
        fn () => SignatureAcceptance::withoutOrganizationScope()
            ->where('recipient_id', $embed['session']->recipient_id)
            ->first(),
        'o aceite eletrônico ser registrado',
    );

    expect($acceptance->consent_statement)->not->toBeEmpty()
        ->and($embed['session']->fresh()->outcome)->toBe('completed')
        ->and(embedEventKinds($page))->toBe(['ready', 'completed']);
});

it('origem não permitida é bloqueada pela CSP: o widget não abre e o embed.js avisa', function () {
    // A sessão é de OUTRA origem cadastrada; a página que tenta enquadrá-la é a de localhost.
    $embed = embedBrowserSession($this, 'https://outro.example.com', [$this->hostOrigin, 'https://outro.example.com']);
    embedHostRoute($embed['url']);

    $page = visit($this->hostOrigin.'/_teste/embed-host');

    $page->assertSee('error:timeout');

    expect(embedEventKinds($page))->toBe(['error'])
        ->and($embed['session']->fresh()->used_at)->toBeNull();
});

it('mensagens com origem ou janela inválidas são ignoradas nos dois sentidos', function () {
    $embed = embedBrowserSession($this, $this->hostOrigin, [$this->hostOrigin]);
    $session = $embed['session']->ulid;
    embedHostRoute($embed['url']);

    // Outra página da MESMA origem do widget (127.0.0.1), num segundo iframe: finge ser o widget
    // falando com o site e finge ser o site falando com o widget.
    Route::get('/_teste/embed-intruso', fn () => response(<<<HTML
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>Intruso</title></head>
<body>
<script>
parent.postMessage({ type: 'assinavelox:completed', v: 1, session: '{$session}', payload: { status: 'completed' } }, '*');
try { parent.frames[0].postMessage({ type: 'assinavelox:ping', v: 1, session: '{$session}', payload: {} }, '*'); } catch (e) {}
document.body.textContent = 'intruso enviou';
</script>
</body>
</html>
HTML));

    $page = visit($this->hostOrigin.'/_teste/embed-host');

    $page->assertSee('ready:identify');

    // 1) A própria página hospedeira forja uma mensagem (origem e janela erradas).
    $page->script("window.postMessage({ type: 'assinavelox:completed', v: 1, session: '{$session}', payload: {} }, '*'); true");

    // 2) O intruso de mesma origem que o widget, em outro iframe.
    $page->script("var f = document.createElement('iframe'); f.src = '{$this->appOrigin}/_teste/embed-intruso'; document.body.appendChild(f); true");

    $page->withinFrame('iframe[src*="embed-intruso"]', function ($frame): void {
        $frame->assertSee('intruso enviou');
    });

    $page->wait(1.5);

    // Nem o `completed` forjado chegou ao site, nem o `ping` do intruso gerou um novo `ready`.
    expect(embedEventKinds($page))->toBe(['ready']);

    // 3) O `ping` legítimo do site (origem e janela certas) é respondido.
    $page->script('window.__widget.ping(); true');

    $readies = 0;

    for ($attempt = 0; $attempt < 40 && $readies < 2; $attempt++) {
        $page->wait(0.25);
        $readies = count(array_filter(embedEventKinds($page), fn (string $kind): bool => $kind === 'ready'));
    }

    expect($readies)->toBe(2)
        ->and(embedEventKinds($page))->not->toContain('completed');
});
