<?php

/*
|--------------------------------------------------------------------------
| Revisão de design — tokens de fonte e URLs canônicas
|--------------------------------------------------------------------------
| DESIGN_SYSTEM §1.2: a família manuscrita `--font-hand: 'Caveat'` (peso 600) é
| usada para renderizar assinaturas (hero do login, campo assinado no PDF,
| pad "Digitar"). O tema declara o token, mas nenhuma face Caveat é servida —
| vite.config.ts carrega só "Exo 2" via bunny() — então o navegador cai em
| "Segoe Script"/cursive do sistema.
|
| ROUTES_AND_PAGES §1.2: `profile.edit` é GET /perfil. As rotas do kit ficaram
| em inglês (/settings/profile, /settings/security, /settings/password) e
| `/perfil` é só um redirect — todas as demais URLs do app são em PT-BR.
*/

it('serve uma face da fonte manuscrita Caveat junto com a Exo 2 (DESIGN §1.2)', function () {
    $manifestPath = public_path('build/manifest.json');

    if (! is_file($manifestPath)) {
        $this->markTestSkipped('Sem public/build/manifest.json (rode npm run build).');
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

    $cssFiles = collect($manifest)
        ->flatMap(fn (array $entry) => array_merge([$entry['file'] ?? null], $entry['css'] ?? []))
        ->filter(fn (?string $file) => $file !== null && str_ends_with($file, '.css'))
        ->unique();

    $fontFaces = $cssFiles
        ->map(fn (string $file) => (string) file_get_contents(public_path('build/'.$file)))
        ->flatMap(function (string $css) {
            preg_match_all('/@font-face\s*\{[^}]*font-family:\s*["\']?([^;"\']+)["\']?;/i', $css, $m);

            return $m[1];
        })
        ->map(fn (string $family) => trim($family))
        ->unique()
        ->values();

    expect($fontFaces->contains('Exo 2'))->toBeTrue('A face "Exo 2" deve estar no build.')
        ->and($fontFaces->contains(fn (string $f) => stripos($f, 'Caveat') !== false))
        ->toBeTrue('Nenhuma @font-face "Caveat" no build; --font-hand cai no fallback do sistema. Faces encontradas: '.$fontFaces->implode(', '));
});

it('expõe o perfil do usuário na URL canônica em português /perfil (ROUTES §1.2)', function () {
    expect(route('profile.edit', absolute: false))->toBe('/perfil');
});

it('não mantém URLs do kit em inglês para páginas de conta (settings/profile, settings/security)', function () {
    $englishPaths = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'settings/'))
        ->unique()
        ->values()
        ->all();

    expect($englishPaths)->toBe([], 'URIs do kit ainda em inglês: '.implode(', ', $englishPaths));
});
