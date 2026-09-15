<?php

use App\Models\EmbeddedSigningSession;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| Flag `embedded_signing` desligada (roadmap T8 — docs/fase-3/widget-embutido.md §10)
|--------------------------------------------------------------------------
| Nada do widget existe: API, página, troca, estado, embed.js e tela de origens respondem 404,
| e a página responde com os cabeçalhos de sempre (DENY + frame-ancestors 'none').
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = storage_path('framework/testing/embed-off-'.Str::random(8));
    signerDisk($this->work);
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

test('com a flag global desligada (o padrão), tudo do widget responde 404', function () {
    $scenario = embedScenario();
    $open = embedOpen($this, $scenario);

    config()->set('assinavelox.features.embedded_signing', false);

    embedCreate($this, $scenario)->assertNotFound();

    $page = $this->get(route('embed.show', ['session' => $open['id']]))->assertNotFound();
    expect($page->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and((string) $page->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");

    $this->postJson(route('embed.exchange', ['session' => $open['id']]), ['token' => 'x'])->assertNotFound();
    $this->getJson(route('embed.state', ['session' => $open['id']]), embedHeaders($open['runtime']))->assertNotFound();
    $this->get(route('embed.script'))->assertNotFound();

    actingAsMember($scenario['owner'], $scenario['organization']);
    $this->get(route('integrations.embed.edit'))->assertNotFound();

    expect(EmbeddedSigningSession::withoutGlobalScopes()->count())->toBe(1);
});

test('sem a flag no plano, a sessão já criada não abre', function () {
    $scenario = embedScenario();
    $open = embedOpen($this, $scenario);

    embedEnable($scenario['organization'], plan: false);

    $this->get(route('embed.show', ['session' => $open['id']]))->assertNotFound();
    $this->getJson(route('embed.state', ['session' => $open['id']]), embedHeaders($open['runtime']))->assertNotFound();
});
