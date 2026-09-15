<?php

use App\Models\AuditEvent;
use App\Services\Envelopes\Sending\AccessLinks;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Phase3/Embed/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda G — regressões das CORREÇÕES do widget
|--------------------------------------------------------------------------
| 1. Convite reemitido ANTES da troca (sem repetir a criação): a troca recusa com `unavailable`
|    e nada de `embedded_session.opened` — a outra metade da correção do achado de reemissão.
| 2. Resposta pelo link do e-mail com a sessão aberta: a API informa o novo valor `closed`.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = storage_path('framework/testing/review-embed-fixes-'.Str::random(8));
    signerDisk($this->work);
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

test('convite reemitido antes da troca: a URL não vira token de execução nem grava embedded_session.opened', function () {
    $scenario = embedScenario();
    $url = (string) embedCreate($this, $scenario, [], (string) Str::uuid())->assertCreated()->json('data.url');

    app(AccessLinks::class)->issue($scenario['recipient']->fresh());

    $this->postJson(route('embed.exchange', ['session' => embedSessionIdFrom($url)]), ['token' => embedTokenFrom($url)])
        ->assertStatus(410)
        ->assertJsonPath('code', 'unavailable');

    expect(AuditEvent::withoutGlobalScopes()->where('event_type', 'embedded_session.opened')->count())->toBe(0);
});

test('resposta pelo link do e-mail com a sessão embutida aberta: a API informa "closed"', function () {
    $scenario = embedScenario();
    $maria = $scenario['recipient'];
    $open = embedOpen($this, $scenario);

    $token = $scenario['tokens'][$maria->email];
    authenticateSigner($this, $token);
    $this->post(route('sign.refuse', ['token' => $token]), ['reason' => 'Não concordo com a cláusula 3.'])->assertRedirect();

    $this->getJson(route('api.v1.envelopes.recipients.embedded_sessions.show', [
        'envelope' => $scenario['envelope']->ulid,
        'recipient' => $maria->ulid,
        'embeddedSession' => $open['id'],
    ]), apiHeaders($scenario['api_token']))
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');
});
