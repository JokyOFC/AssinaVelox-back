<?php

use App\Enums\SigningOrder;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Phase3/Embed/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial G-EMBED — revogar depois do aceite
|--------------------------------------------------------------------------
| `DELETE …/embedded-sessions/{id}` promete "Idempotente. Um aceite já registrado nunca é
| desfeito." Mas `EmbeddedSessionRevoker::revoke` não olha `outcome`, e
| `EmbeddedSigningSession::status()` testa `isRevoked()` ANTES de `outcome`: uma sessão que já
| registrou o aceite passa a ser informada à integração como `revoked`, e a trilha ganha um
| `embedded_session.revoked` posterior ao `acceptance.recorded` — um integrador que consulta o
| status (ou lê a trilha) conclui que a assinatura foi encerrada/revogada.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = storage_path('framework/testing/review-embed-revoke-'.Str::random(8));
    signerDisk($this->work);
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

test('revogar uma sessão que já registrou o aceite não troca o status "completed" por "revoked"', function () {
    $scenario = embedScenario([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test'],
        ['name' => 'João Pedro Lima', 'email' => 'joao@exemplo.test'],
    ], SigningOrder::Parallel);
    $maria = $scenario['recipients']['maria@exemplo.test'];
    $open = embedOpen($this, $scenario, $maria);

    $state = embedAuthenticate($this, $open);
    $this->get($state['documents'][0]['pdf_url'], embedHeaders($open['runtime']))->assertOk();
    $this->postJson(route('embed.complete', ['session' => $open['id']]), embedAcceptancePayload($state), embedHeaders($open['runtime']))
        ->assertOk();

    $show = route('api.v1.envelopes.recipients.embedded_sessions.show', [
        'envelope' => $scenario['envelope']->ulid,
        'recipient' => $maria->ulid,
        'embeddedSession' => $open['id'],
    ]);

    $this->getJson($show, apiHeaders($scenario['api_token']))->assertOk()->assertJsonPath('data.status', 'completed');

    // A integração "limpa" a sessão depois do aceite (caso comum: fechar o modal do site).
    $this->deleteJson($show, [], apiHeaders($scenario['api_token']))->assertOk();

    // Esperado: o desfecho registrado continua sendo o que a API informa.
    $this->getJson($show, apiHeaders($scenario['api_token']))
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');
});

test('revogar depois do aceite não grava embedded_session.revoked na trilha do envelope', function () {
    $scenario = embedScenario([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test'],
        ['name' => 'João Pedro Lima', 'email' => 'joao@exemplo.test'],
    ], SigningOrder::Parallel);
    $maria = $scenario['recipients']['maria@exemplo.test'];
    $open = embedOpen($this, $scenario, $maria);

    $state = embedAuthenticate($this, $open);
    $this->get($state['documents'][0]['pdf_url'], embedHeaders($open['runtime']))->assertOk();
    $this->postJson(route('embed.complete', ['session' => $open['id']]), embedAcceptancePayload($state), embedHeaders($open['runtime']))
        ->assertOk();

    $this->deleteJson(route('api.v1.envelopes.recipients.embedded_sessions.destroy', [
        'envelope' => $scenario['envelope']->ulid,
        'recipient' => $maria->ulid,
        'embeddedSession' => $open['id'],
    ]), [], apiHeaders($scenario['api_token']))->assertOk();

    expect(AuditEvent::withoutGlobalScopes()
        ->where('event_type', 'embedded_session.revoked')
        ->where('recipient_id', $maria->id)
        ->count())->toBe(0);
});
