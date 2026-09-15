<?php

use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\AuditEvent;
use App\Models\EmbeddedSigningSession;
use App\Models\SignatureAcceptance;
use App\Services\Embed\AllowedOrigins;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Signing\Channels\SenderPins;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| Widget embutido — troca, sessão isolada, escopo e aceite (docs/fase-3/widget-embutido.md §3)
|--------------------------------------------------------------------------
| Dois signatários em PARALELO nos fluxos de aceite: o primeiro aceite não conclui o envelope
| (nada de finalização nem de pdftool aqui), e o que se prova é o widget.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = storage_path('framework/testing/embed-flow-'.Str::random(8));
    signerDisk($this->work);
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

function embedParallelScenario(): array
{
    return embedScenario([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test'],
        ['name' => 'João Pedro Lima', 'email' => 'joao@exemplo.test'],
    ], SigningOrder::Parallel);
}

test('a página só entrega a casca; a troca vale uma única vez e nenhuma resposta grava cookie', function () {
    $scenario = embedScenario();
    $url = (string) embedCreate($this, $scenario)->assertCreated()->json('data.url');
    $id = embedSessionIdFrom($url);
    $token = embedTokenFrom($url);

    $page = $this->get(route('embed.show', ['session' => $id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('embed/sign')
            ->where('invalid', false)
            ->where('session_id', $id)
            ->where('parent_origin', EMBED_ORIGIN)
            ->where('protocol_version', 1)
            ->where('endpoints.exchange', route('embed.exchange', ['session' => $id], false))
            ->missing('envelope')
            ->missing('recipient'));

    expect($page->getContent())->not->toContain($token)
        ->and($page->headers->getCookies())->toBe([]);

    $exchange = $this->postJson(route('embed.exchange', ['session' => $id]), ['token' => $token])
        ->assertOk()
        ->assertJsonStructure(['token', 'expires_at', 'session_id']);

    expect($exchange->headers->getCookies())->toBe([])
        ->and($exchange->json('token'))->not->toBe($token);

    $this->postJson(route('embed.exchange', ['session' => $id]), ['token' => $token])
        ->assertStatus(410)
        ->assertJsonPath('code', 'link_used');

    $this->postJson(route('embed.exchange', ['session' => $id]), ['token' => Str::random(43)])
        ->assertNotFound()
        ->assertJsonPath('code', 'invalid_link');

    $row = EmbeddedSigningSession::withoutGlobalScopes()->where('ulid', $id)->firstOrFail();
    expect($row->used_at)->not->toBeNull()
        ->and($row->runtime_token_digest)->toBe(hash('sha256', $exchange->json('token')))
        ->and(AuditEvent::withoutGlobalScopes()->where('event_type', 'embedded_session.opened')->value('payload'))
        ->toMatchArray(['session' => $id, 'origin' => EMBED_ORIGIN]);
});

test('URL vencida: 410 link_expired, e a sessão não abre depois', function () {
    $scenario = embedScenario();
    $url = (string) embedCreate($this, $scenario, ['expires_in' => 60])->assertCreated()->json('data.url');

    $this->travel(2)->minutes();

    $this->postJson(route('embed.exchange', ['session' => embedSessionIdFrom($url)]), ['token' => embedTokenFrom($url)])
        ->assertStatus(410)
        ->assertJsonPath('code', 'link_expired');
});

test('o estado exige o token de execução no cabeçalho e não expõe o convite nem URL /assinar', function () {
    $scenario = embedScenario();
    $open = embedOpen($this, $scenario);
    $other = embedOpen($this, embedScenario());

    $this->getJson(route('embed.state', ['session' => $open['id']]))->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    $this->getJson(route('embed.state', ['session' => $open['id']]), embedHeaders($other['runtime']))->assertUnauthorized();

    $response = $this->getJson(route('embed.state', ['session' => $open['id']]), embedHeaders($open['runtime']))
        ->assertOk()
        ->assertJsonPath('state.screen', 'identify')
        ->assertJsonPath('state.token', null)
        ->assertJsonPath('state.document', null)
        ->assertJsonPath('state.embed.session_id', $open['id']);

    $raw = (string) $response->getContent();
    $invitation = $scenario['tokens']['maria@exemplo.test'];

    expect($raw)->not->toContain($invitation)
        ->and($raw)->not->toContain('/assinar/')
        ->and($raw)->not->toContain('maria@exemplo.test')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->getCookies())->toBe([]);
});

test('fluxo completo pelo widget: código, documento, aceite — mesmas regras do fluxo público', function () {
    $scenario = embedParallelScenario();
    $maria = $scenario['recipients']['maria@exemplo.test'];
    $open = embedOpen($this, $scenario, $maria);

    $state = embedAuthenticate($this, $open);

    expect($state['screen'])->toBe('sign')
        ->and($state['authorization']['token'])->toBeString()
        ->and($state['documents'])->toHaveCount(1);

    $documentUrl = $state['documents'][0]['pdf_url'];
    expect($documentUrl)->toStartWith('/embed/v1/sessoes/'.$open['id'].'/documentos/')
        ->and($state['document']['pdf_url'])->toBe($documentUrl);

    // O PDF exige o token no cabeçalho.
    $this->get($documentUrl)->assertUnauthorized();

    $pdf = $this->get($documentUrl, embedHeaders($open['runtime']))->assertOk();
    expect((string) $pdf->headers->get('Content-Type'))->toContain('application/pdf');

    $this->postJson(route('embed.complete', ['session' => $open['id']]), embedAcceptancePayload($state), embedHeaders($open['runtime']))
        ->assertOk()
        ->assertJsonPath('message', 'Aceite registrado.')
        ->assertJsonPath('state.screen', 'already_signed_pending_others')
        ->assertJsonPath('state.receipt.can_download', false)
        ->assertJsonPath('state.receipt.download_url', null)
        ->assertJsonPath('state.receipt.final_pdf_url', null);

    expect(SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $maria->id)->exists())->toBeTrue()
        ->and($maria->fresh()->status)->toBe(RecipientStatus::Signed);

    $row = EmbeddedSigningSession::withoutGlobalScopes()->where('ulid', $open['id'])->firstOrFail();
    expect($row->outcome)->toBe('completed')
        ->and($row->completed_at)->not->toBeNull()
        ->and($row->session_state)->toBeNull();

    $presented = AuditEvent::withoutGlobalScopes()
        ->where('event_type', 'document.presented')
        ->where('recipient_id', $maria->id)
        ->firstOrFail();
    expect($presented->payload['channel'])->toBe('embedded')
        ->and($presented->payload['embedded_session'])->toBe($open['id']);

    // Nada mais a registrar por esta sessão.
    $this->postJson(route('embed.complete', ['session' => $open['id']]), embedAcceptancePayload($state), embedHeaders($open['runtime']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_signable');
});

test('sem a confirmação visual o aceite não é registrado (422)', function () {
    $scenario = embedParallelScenario();
    $open = embedOpen($this, $scenario, $scenario['recipients']['maria@exemplo.test']);
    $state = embedAuthenticate($this, $open);

    $this->get($state['documents'][0]['pdf_url'], embedHeaders($open['runtime']))->assertOk();

    $this->postJson(route('embed.complete', ['session' => $open['id']]), embedAcceptancePayload($state, interaction: false), embedHeaders($open['runtime']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['interaction']);

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0);
});

test('escopo: sem o código não há PDF, e documento de outro envelope responde 404', function () {
    $scenario = embedParallelScenario();
    $open = embedOpen($this, $scenario, $scenario['recipients']['maria@exemplo.test']);
    $document = $scenario['version']->document()->withoutGlobalScopes()->firstOrFail();

    $this->get(route('embed.document', ['session' => $open['id'], 'document' => $document->ulid]), embedHeaders($open['runtime']))
        ->assertNotFound();

    embedAuthenticate($this, $open);

    $foreign = embedScenario();
    $foreignDocument = $foreign['version']->document()->withoutGlobalScopes()->firstOrFail();

    $this->get(route('embed.document', ['session' => $open['id'], 'document' => $foreignDocument->ulid]), embedHeaders($open['runtime']))
        ->assertNotFound();

    $this->get(route('embed.document', ['session' => $open['id'], 'document' => $document->ulid]), embedHeaders($open['runtime']))
        ->assertOk();
});

test('recusa pelo widget exige a sessão e o motivo, e registra o desfecho', function () {
    $scenario = embedParallelScenario();
    $maria = $scenario['recipients']['maria@exemplo.test'];
    $open = embedOpen($this, $scenario, $maria);

    $this->postJson(route('embed.refuse', ['session' => $open['id']]), ['reason' => 'Não concordo com a cláusula 3.'], embedHeaders($open['runtime']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'code_required');

    embedAuthenticate($this, $open);

    $this->postJson(route('embed.refuse', ['session' => $open['id']]), ['reason' => 'curto'], embedHeaders($open['runtime']))
        ->assertStatus(422);

    $this->postJson(route('embed.refuse', ['session' => $open['id']]), ['reason' => 'Não concordo com a cláusula 3.'], embedHeaders($open['runtime']))
        ->assertOk()
        ->assertJsonPath('state.screen', 'refused');

    expect($maria->fresh()->status)->toBe(RecipientStatus::Refused)
        ->and(EmbeddedSigningSession::withoutGlobalScopes()->where('ulid', $open['id'])->value('outcome'))->toBe('refused');
});

test('PIN do remetente: o portão do PIN atravessa as requisições pela sessão isolada', function () {
    $scenario = embedScenario();
    $maria = $scenario['recipient'];
    app(SenderPins::class)->set($maria, '739154');
    $open = embedOpen($this, $scenario);

    $state = embedAuthenticate($this, $open);

    expect($state['screen'])->toBe('identify')
        ->and($state['signer_auth']['step'])->toBe('pin');

    $this->postJson(route('embed.pin.verify', ['session' => $open['id']]), ['pin' => '739155'], embedHeaders($open['runtime']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_pin');

    $this->postJson(route('embed.pin.verify', ['session' => $open['id']]), ['pin' => '739154'], embedHeaders($open['runtime']))
        ->assertOk()
        ->assertJsonPath('state.screen', 'sign');

    // O estado persistido entre requisições é cifrado: o token da sessão não aparece em claro.
    $raw = (string) DB::table('embedded_signing_sessions')->where('ulid', $open['id'])->value('session_state');
    expect($raw)->not->toBe('')
        ->and($raw)->not->toContain('signer_session');
});

test('token de execução vence em 30 minutos', function () {
    $scenario = embedScenario();
    $open = embedOpen($this, $scenario);

    $this->travel(31)->minutes();

    $this->getJson(route('embed.state', ['session' => $open['id']]), embedHeaders($open['runtime']))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'session_expired');
});

test('origem descadastrada derruba a sessão e a página; convite revogado vira indisponível', function () {
    $scenario = embedScenario();
    $open = embedOpen($this, $scenario);

    app(AccessLinks::class)->revokeFor($scenario['recipient']);

    $this->getJson(route('embed.state', ['session' => $open['id']]), embedHeaders($open['runtime']))
        ->assertOk()
        ->assertJsonPath('state.screen', 'unavailable');

    $this->postJson(route('embed.otp.send', ['session' => $open['id']]), [], embedHeaders($open['runtime']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_signable');

    AllowedOrigins::replace($scenario['organization'], []);

    $this->getJson(route('embed.state', ['session' => $open['id']]), embedHeaders($open['runtime']))
        ->assertStatus(410)
        ->assertJsonPath('code', 'origin_removed');

    $this->get(route('embed.show', ['session' => $open['id']]))->assertNotFound();
});

test('a sessão do painel não é lida pelo widget: estar logado não abre nada', function () {
    $scenario = embedScenario();
    $open = embedOpen($this, $scenario);

    actingAsMember($scenario['owner'], $scenario['organization']);

    $this->getJson(route('embed.state', ['session' => $open['id']]))->assertUnauthorized();
    $this->postJson(route('embed.otp.send', ['session' => $open['id']]))->assertUnauthorized();
});
