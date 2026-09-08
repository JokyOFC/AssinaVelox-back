<?php

use Illuminate\Support\Facades\Vite;

/*
|--------------------------------------------------------------------------
| Smoke: CSP com nonce + assets do build de produção
|--------------------------------------------------------------------------
| Renderiza GET / e GET /login com o Vite REAL (sem withoutVite) para garantir
| que o nonce da CSP chega às tags <script> e que o manifest do build resolve os
| assets. Pulado quando public/build/manifest.json não existe (ambiente sem
| `npm run build`).
*/

beforeEach(function (): void {
    if (! is_file(public_path('build/manifest.json'))) {
        $this->markTestSkipped('public/build/manifest.json ausente — rode `npm run build` antes deste smoke test.');
    }
});

test('páginas públicas saem com CSP com nonce e assets do build', function (string $uri): void {
    $response = $this->get($uri);

    $response->assertOk();
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("script-src 'self' 'nonce-")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'");

    preg_match("/'nonce-([A-Za-z0-9+\\/=_-]+)'/", $csp, $matches);
    $nonce = $matches[1] ?? null;
    expect($nonce)->not->toBeNull()->and($nonce)->toBe(Vite::cspNonce());

    $html = $response->getContent();

    // Toda tag <script> EXECUTÁVEL carrega o nonce da política (o bloco JSON `data-page`
    // do Inertia tem type="application/json" e não é executado, logo fica fora da CSP).
    preg_match_all('/<script\b[^>]*>/i', $html, $scripts);
    $executable = array_values(array_filter(
        $scripts[0],
        fn (string $tag): bool => ! preg_match('/type="application\/(ld\+)?json"/i', $tag),
    ));
    expect($executable)->not->toBeEmpty();

    foreach ($executable as $tag) {
        expect($tag)->toContain('nonce="'.$nonce.'"');
    }

    // Assets do build (manifest) e não do dev server.
    expect($html)->toContain('/build/assets/app-')
        ->and($html)->toMatch('#/build/assets/app-[^"]+\.js#')
        ->and($html)->toMatch('#/build/assets/app-[^"]+\.css#')
        ->and($html)->not->toContain('@vite/client');
})->with(['/', '/login']);
