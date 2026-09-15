<?php

use App\Services\Embed\AllowedOrigins;
use App\Services\Embed\EmbedFrame;
use App\Services\Embed\Http\EmbedSecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| Cabeçalhos por rota (G-EMBED, docs/fase-3/widget-embutido.md §5)
|--------------------------------------------------------------------------
| Só a página do widget com sessão encontrada troca DENY + frame-ancestors 'none' pela origem
| EXATA da sessão. Todo o resto — inclusive as demais rotas /embed/* — continua DENY.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = storage_path('framework/testing/embed-headers-'.Str::random(8));
    signerDisk($this->work);
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

test('página do widget: sem X-Frame-Options, frame-ancestors com a origem exata e blob: no connect-src', function () {
    $scenario = embedScenario();
    $url = (string) embedCreate($this, $scenario)->assertCreated()->json('data.url');

    $response = $this->get(route('embed.show', ['session' => embedSessionIdFrom($url)]))->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($response->headers->has('X-Frame-Options'))->toBeFalse()
        ->and($csp)->toContain('frame-ancestors '.EMBED_ORIGIN)
        ->and($csp)->not->toContain("frame-ancestors 'none'")
        ->and(substr_count($csp, 'frame-ancestors'))->toBe(1)
        ->and($csp)->toMatch('/connect-src [^;]*blob:/')
        ->and($csp)->toContain("frame-src 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow, noarchive');
});

test('sessão inexistente, flag do plano desligada ou origem descadastrada: DENY e none', function () {
    $scenario = embedScenario();
    $url = (string) embedCreate($this, $scenario)->assertCreated()->json('data.url');
    $page = route('embed.show', ['session' => embedSessionIdFrom($url)]);

    $unknown = $this->get(route('embed.show', ['session' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']))->assertNotFound();
    expect($unknown->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and((string) $unknown->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");

    embedEnable($scenario['organization'], plan: false);
    $planOff = $this->get($page)->assertNotFound();
    expect($planOff->headers->get('X-Frame-Options'))->toBe('DENY');

    embedEnable($scenario['organization']);
    AllowedOrigins::replace($scenario['organization'], ['https://outro.example.com']);
    $removed = $this->get($page)->assertNotFound();
    expect($removed->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and((string) $removed->headers->get('Content-Security-Policy'))->not->toContain(EMBED_ORIGIN);
});

test('CSP desligada ou em report-only pela configuração não libera o enquadramento', function () {
    $scenario = embedScenario();
    $url = (string) embedCreate($this, $scenario)->assertCreated()->json('data.url');
    $page = route('embed.show', ['session' => embedSessionIdFrom($url)]);

    config(['assinavelox.security_headers.csp_enabled' => false]);
    $off = $this->get($page)->assertOk();
    expect($off->headers->get('Content-Security-Policy'))->toBe('frame-ancestors '.EMBED_ORIGIN)
        ->and($off->headers->has('X-Frame-Options'))->toBeFalse();

    config(['assinavelox.security_headers.csp_enabled' => true, 'assinavelox.security_headers.csp_report_only' => true]);
    $reportOnly = $this->get($page)->assertOk();
    expect($reportOnly->headers->get('Content-Security-Policy'))->toBe('frame-ancestors '.EMBED_ORIGIN)
        ->and((string) $reportOnly->headers->get('Content-Security-Policy-Report-Only'))->toContain('frame-ancestors '.EMBED_ORIGIN);
});

test('JSON do widget e troca continuam DENY + none (não são páginas)', function () {
    $scenario = embedScenario();
    $open = embedOpen($this, $scenario);

    foreach ([
        $this->getJson(route('embed.state', ['session' => $open['id']]), embedHeaders($open['runtime'])),
        $this->postJson(route('embed.exchange', ['session' => $open['id']]), ['token' => 'x']),
    ] as $response) {
        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    }
});

test('com a flag ligada, TODAS as rotas GET sem parâmetro continuam com DENY e frame-ancestors none', function () {
    $scenario = embedScenario();
    embedCreate($this, $scenario)->assertCreated();

    $excluded = ['horizon', '_inertia', 'storage/', 'sanctum/', 'docs/api', '_scramble/', '_boost', '_debugbar', 'telescope'];
    $checked = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{') || Str::startsWith($uri, $excluded)) {
            continue;
        }

        $response = $this->get('/'.ltrim($uri, '/'));

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY', "X-Frame-Options em /{$uri}");

        $csp = $response->headers->get('Content-Security-Policy');

        if ($csp !== null) {
            expect(str_contains($csp, "frame-ancestors 'none'"))->toBeTrue("frame-ancestors em /{$uri}");
        }

        $checked[] = $uri;
    }

    expect(count($checked))->toBeGreaterThan(30)
        ->and($checked)->toContain('embed/v1/embed.js');

    // Rotas autenticadas comuns, com a flag ligada para a organização.
    actingAsMember($scenario['owner'], $scenario['organization']);

    foreach (['dashboard', 'envelopes.index', 'integrations.index', 'integrations.embed.edit', 'settings.general'] as $name) {
        $response = $this->get(route($name));

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY', $name)
            ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    }
});

test('a marca de enquadramento só vale em /embed/*', function () {
    $request = Request::create('/painel');
    EmbedFrame::allowFramingBy($request, EMBED_ORIGIN);

    expect(EmbedFrame::frameAncestor($request))->toBeNull();

    $embed = Request::create('/embed/v1/sessoes/01HZZZZZZZZZZZZZZZZZZZZZZZ');
    EmbedFrame::allowFramingBy($embed, 'https://*.example.com');

    expect(EmbedFrame::frameAncestor($embed))->toBeNull();

    expect(EmbedSecurityHeaders::rewrite("default-src 'self'; connect-src 'self'; frame-ancestors 'none'", EMBED_ORIGIN))
        ->toBe("default-src 'self'; connect-src 'self' blob:; frame-ancestors ".EMBED_ORIGIN);
});

test('embed.js: servido do build com CORP cross-origin e cache curto', function () {
    config()->set('assinavelox.features.embedded_signing', true);

    $public = storage_path('framework/testing/embed-public-'.Str::random(8));
    File::ensureDirectoryExists($public.'/build/assets');
    File::put($public.'/build/manifest.json', json_encode([
        'resources/js/embed/embed.ts' => ['file' => 'assets/embed-teste.js', 'isEntry' => true],
    ]));
    File::put($public.'/build/assets/embed-teste.js', '(function(){window.AssinaVelox={};})();');

    app()->usePublicPath($public);

    try {
        $response = $this->get(route('embed.script'))->assertOk();

        expect((string) $response->headers->get('Content-Type'))->toContain('javascript')
            ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('cross-origin')
            ->and((string) $response->headers->get('Cache-Control'))->toContain('max-age=300')
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');

        File::put($public.'/build/manifest.json', json_encode([]));
        $this->get(route('embed.script'))->assertNotFound();
    } finally {
        File::deleteDirectory($public);
    }
});
