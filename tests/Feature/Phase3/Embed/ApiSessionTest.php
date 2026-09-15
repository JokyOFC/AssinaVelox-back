<?php

use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Models\AuditEvent;
use App\Models\EmbeddedSigningSession;
use App\Services\Envelopes\Sending\AccessLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| API v1 — sessão de assinatura embutida (G-EMBED, docs/fase-3/widget-embutido.md §2)
|--------------------------------------------------------------------------
| Criação, idempotência, isolamento entre organizações, origens, estado do participante,
| ability, flag, consulta, revogação e teto por participante.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = storage_path('framework/testing/embed-api-'.Str::random(8));
    signerDisk($this->work);
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

test('cria a sessão: URL de uso único com o token só no fragmento e nada em claro no banco', function () {
    $scenario = embedScenario();

    $response = embedCreate($this, $scenario)->assertCreated();
    $data = $response->json('data');

    expect($data['object'])->toBe('embedded_signing_session')
        ->and($data['status'])->toBe('pending')
        ->and($data['origin'])->toBe(EMBED_ORIGIN)
        ->and($data['recipient_id'])->toBe($scenario['recipient']->ulid)
        ->and($data['envelope_id'])->toBe($scenario['envelope']->ulid)
        ->and($data['url'])->toStartWith(route('embed.show', ['session' => $data['id']]).'#t=')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    $response->assertHeader('Location', route('api.v1.envelopes.recipients.embedded_sessions.show', [
        'envelope' => $scenario['envelope']->ulid,
        'recipient' => $scenario['recipient']->ulid,
        'embeddedSession' => $data['id'],
    ]));

    $token = embedTokenFrom($data['url']);
    expect(strlen($token))->toBeGreaterThan(30);

    $row = EmbeddedSigningSession::withoutGlobalScopes()->where('ulid', $data['id'])->firstOrFail();
    expect($row->token_digest)->toBe(hash('sha256', $token))
        ->and($row->runtime_token_digest)->toBeNull();

    // O token não fica em lugar nenhum: nem na sessão, nem no armazenamento de idempotência,
    // nem na trilha, nem no registro de requisições da API.
    $dump = json_encode([
        DB::table('embedded_signing_sessions')->get(),
        DB::table('api_idempotency_keys')->get(),
        DB::table('audit_events')->get(),
        DB::table('api_request_logs')->get(),
    ]);
    expect($dump)->not->toContain($token);

    $event = AuditEvent::withoutGlobalScopes()->where('event_type', 'embedded_session.created')->firstOrFail();
    expect($event->payload['origin'])->toBe(EMBED_ORIGIN)
        ->and($event->payload['session'])->toBe($data['id'])
        ->and($event->recipient_id)->toBe($scenario['recipient']->id)
        ->and($event->envelope_id)->toBe($scenario['envelope']->id);
});

test('idempotência: mesma chave devolve a mesma sessão com URL nova; outro pedido ou sessão usada, 409', function () {
    $scenario = embedScenario();
    $key = (string) Str::uuid();

    $first = embedCreate($this, $scenario, [], $key)->assertCreated();
    $second = embedCreate($this, $scenario, [], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.url'))->not->toBe($first->json('data.url'))
        ->and(EmbeddedSigningSession::withoutGlobalScopes()->count())->toBe(1);

    // A URL anterior deixou de valer.
    $this->postJson(route('embed.exchange', ['session' => $first->json('data.id')]), [
        'token' => embedTokenFrom($first->json('data.url')),
    ])->assertNotFound()->assertJsonPath('code', 'invalid_link');

    embedCreate($this, $scenario, ['expires_in' => 120], $key)
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:assinavelox:problem:idempotency-key-reused');

    $this->postJson(route('embed.exchange', ['session' => $second->json('data.id')]), [
        'token' => embedTokenFrom($second->json('data.url')),
    ])->assertOk();

    embedCreate($this, $scenario, [], $key)
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:assinavelox:problem:embedded-session-closed');
});

test('token de outra organização não enxerga o envelope nem o participante (404)', function () {
    $scenario = embedScenario();
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    embedEnable($other);
    $foreign = apiIssueToken($other, $otherOwner);

    $this->postJson(embedCreateUrl($scenario), ['origin' => EMBED_ORIGIN], apiHeaders($foreign, apiIdem()))
        ->assertNotFound()
        ->assertJsonPath('type', 'urn:assinavelox:problem:not-found');

    expect(EmbeddedSigningSession::withoutGlobalScopes()->count())->toBe(0);
});

test('participante de outro envelope da mesma organização não casa com a rota (404)', function () {
    $scenario = embedScenario();
    $otherEnvelope = signerEnvelope([], SigningOrder::Sequential, [], $scenario['organization'], $scenario['owner']);
    $stranger = array_values($otherEnvelope['recipients'])[0];

    $this->postJson(route('api.v1.envelopes.recipients.embedded_sessions.store', [
        'envelope' => $scenario['envelope']->ulid,
        'recipient' => $stranger->ulid,
    ]), ['origin' => EMBED_ORIGIN], apiHeaders($scenario['api_token'], apiIdem()))->assertNotFound();
});

test('origem fora da lista ou fora do formato exato: 422 com errors.origin', function (string $origin) {
    $scenario = embedScenario();

    $this->postJson(embedCreateUrl($scenario), ['origin' => $origin], apiHeaders($scenario['api_token'], apiIdem()))
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:assinavelox:problem:validation-failed')
        ->assertJsonStructure(['errors' => ['origin']]);

    expect(EmbeddedSigningSession::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'outro site' => ['https://outro.example.com'],
    'http' => ['http://cliente.example.com.br'],
    'curinga' => ['https://*.example.com.br'],
    'com caminho' => ['https://cliente.example.com.br/checkout'],
    'com usuário' => ['https://user@cliente.example.com.br'],
    'sem esquema' => ['cliente.example.com.br'],
    'porta diferente' => ['https://cliente.example.com.br:8443'],
]);

test('a origem é comparada na forma canônica (maiúsculas, porta padrão e barra final)', function () {
    $scenario = embedScenario();

    embedCreate($this, $scenario, ['origin' => 'HTTPS://Cliente.Example.com.br:443/'])
        ->assertCreated()
        ->assertJsonPath('data.origin', EMBED_ORIGIN);
});

test('fora da vez no sequencial, mesmo com convite, a sessão é recusada', function () {
    $scenario = embedScenario([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test'],
        ['name' => 'João Pedro Lima', 'email' => 'joao@exemplo.test'],
    ]);

    // O helper cria links para todos; o segundo continua fora da vez (current_order = 1).
    $second = $scenario['recipients']['joao@exemplo.test'];

    expect(app(AccessLinks::class)->activeFor($second))->not->toBeNull();

    embedCreate($this, $scenario, [], null, $second)
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:assinavelox:problem:recipient-not-active');
});

test('sem convite ativo ou com documento fora de andamento, a sessão é recusada', function () {
    $scenario = embedScenario();

    app(AccessLinks::class)->revokeFor($scenario['recipient']);

    embedCreate($this, $scenario)
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:assinavelox:problem:recipient-not-invited');

    $scenario['envelope']->forceFill(['status' => EnvelopeStatus::Canceled])->save();

    embedCreate($this, $scenario)
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:assinavelox:problem:invalid-status');
});

test('ability ausente: 403 missing-ability; flag desligada (global ou plano): 404', function () {
    $scenario = embedScenario();
    $limited = apiIssueToken($scenario['organization'], $scenario['owner'], ['envelopes:read', 'recipients:read']);

    $this->postJson(embedCreateUrl($scenario), ['origin' => EMBED_ORIGIN], apiHeaders($limited, apiIdem()))
        ->assertForbidden()
        ->assertJsonPath('required_ability', 'embedded_signing:manage');

    embedEnable($scenario['organization'], plan: false);
    embedCreate($this, $scenario)->assertNotFound();

    embedEnable($scenario['organization']);
    config()->set('assinavelox.features.embedded_signing', false);
    embedCreate($this, $scenario)->assertNotFound();
});

test('consulta e revogação: status muda, a URL e o widget aberto deixam de valer', function () {
    $scenario = embedScenario();
    $open = embedOpen($this, $scenario);

    $show = route('api.v1.envelopes.recipients.embedded_sessions.show', [
        'envelope' => $scenario['envelope']->ulid,
        'recipient' => $scenario['recipient']->ulid,
        'embeddedSession' => $open['id'],
    ]);

    $this->getJson($show, apiHeaders($scenario['api_token']))
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonMissingPath('data.url');

    $this->deleteJson($show, [], apiHeaders($scenario['api_token']))
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked');

    // Idempotente.
    $this->deleteJson($show, [], apiHeaders($scenario['api_token']))->assertOk()->assertJsonPath('data.status', 'revoked');

    $this->getJson(route('embed.state', ['session' => $open['id']]), embedHeaders($open['runtime']))
        ->assertStatus(410)
        ->assertJsonPath('code', 'session_revoked');

    expect(AuditEvent::withoutGlobalScopes()->where('event_type', 'embedded_session.revoked')->count())->toBe(1);
});

test('sessão revogada antes de abrir: a troca responde 410 só para quem tem o token', function () {
    $scenario = embedScenario();
    $url = (string) embedCreate($this, $scenario)->assertCreated()->json('data.url');
    $id = embedSessionIdFrom($url);

    $this->deleteJson(route('api.v1.envelopes.recipients.embedded_sessions.destroy', [
        'envelope' => $scenario['envelope']->ulid,
        'recipient' => $scenario['recipient']->ulid,
        'embeddedSession' => $id,
    ]), [], apiHeaders($scenario['api_token']))->assertOk();

    $this->postJson(route('embed.exchange', ['session' => $id]), ['token' => 'x'.embedTokenFrom($url)])
        ->assertNotFound()
        ->assertJsonPath('code', 'invalid_link');

    $this->postJson(route('embed.exchange', ['session' => $id]), ['token' => embedTokenFrom($url)])
        ->assertStatus(410)
        ->assertJsonPath('code', 'link_revoked');
});

test('sessão de outro participante não aparece na rota deste (404)', function () {
    $scenario = embedScenario([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test'],
        ['name' => 'João Pedro Lima', 'email' => 'joao@exemplo.test'],
    ], SigningOrder::Parallel);
    $maria = $scenario['recipients']['maria@exemplo.test'];
    $joao = $scenario['recipients']['joao@exemplo.test'];

    $id = embedCreate($this, $scenario, [], null, $maria)->assertCreated()->json('data.id');

    $this->getJson(route('api.v1.envelopes.recipients.embedded_sessions.show', [
        'envelope' => $scenario['envelope']->ulid,
        'recipient' => $joao->ulid,
        'embeddedSession' => $id,
    ]), apiHeaders($scenario['api_token']))->assertNotFound();
});

test('teto de sessões utilizáveis por participante', function () {
    config()->set('assinavelox.embedded_signing.max_live_per_recipient', 2);
    $scenario = embedScenario();

    embedCreate($this, $scenario)->assertCreated();
    embedCreate($this, $scenario)->assertCreated();
    embedCreate($this, $scenario)
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:assinavelox:problem:too-many-sessions');
});
