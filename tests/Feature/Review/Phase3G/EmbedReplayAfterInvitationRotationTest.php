<?php

use App\Models\AuditEvent;
use App\Services\Envelopes\Sending\AccessLinks;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Phase3/Embed/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial G-EMBED — reemissão por Idempotency-Key presa a convite revogado
|--------------------------------------------------------------------------
| `EmbeddedSessionIssuer::issue` confere o convite ATIVO (`activeFor`) e depois, na repetição
| com a mesma chave, reemite a URL da sessão ANTIGA — que continua presa ao `access_link_id` do
| convite que já foi revogado (reenvio, troca de e-mail). A API devolve 201 com uma URL nova e
| utilizável; a troca grava `embedded_session.opened` na trilha, e o widget só então descobre
| que o convite não vale ("indisponível"). A repetição deveria recusar (409) como faz com a
| sessão usada ou revogada.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = storage_path('framework/testing/review-embed-replay-'.Str::random(8));
    signerDisk($this->work);
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

test('repetir a criação depois de o convite ser reemitido não devolve URL presa ao convite revogado', function () {
    $scenario = embedScenario();
    $key = (string) Str::uuid();

    embedCreate($this, $scenario, [], $key)->assertCreated();

    // O remetente reenvia o convite: o link anterior é revogado e um novo fica ativo.
    app(AccessLinks::class)->issue($scenario['recipient']->fresh());

    $replay = embedCreate($this, $scenario, [], $key);

    // Esperado: 409 (a sessão da chave está presa a um convite que não vale mais).
    expect($replay->status())->toBe(409);
});

test('a URL reemitida para convite revogado não chega a gravar embedded_session.opened', function () {
    $scenario = embedScenario();
    $key = (string) Str::uuid();

    embedCreate($this, $scenario, [], $key)->assertCreated();
    app(AccessLinks::class)->issue($scenario['recipient']->fresh());

    $url = (string) embedCreate($this, $scenario, [], $key)->json('data.url');

    if ($url !== '') {
        $this->postJson(route('embed.exchange', ['session' => embedSessionIdFrom($url)]), [
            'token' => embedTokenFrom($url),
        ]);
    }

    expect(AuditEvent::withoutGlobalScopes()->where('event_type', 'embedded_session.opened')->count())->toBe(0);
});
