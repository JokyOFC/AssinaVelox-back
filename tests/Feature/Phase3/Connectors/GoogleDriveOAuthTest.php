<?php

use App\Enums\MembershipRole;
use App\Integrations\GoogleDrive\GoogleOAuthClient;
use App\Jobs\Documents\ProcessDocumentUpload;
use App\Services\CloudImport\GoogleImportSession;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| Google Drive — OAuth com PKCE e `state` de uso único (docs/fase-3/conectores.md §4.1)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Bus::fake([ProcessDocumentUpload::class]);
    Storage::fake('documents');
    connectorsDns();
    connectorsConfigure();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    connectorsEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
    $this->envelope = connectorsDraft($this->organization, $this->owner);
});

test('início: redireciona ao Google com state, PKCE S256 e só o escopo drive.file, sem nenhuma chamada', function () {
    Http::fake();

    $response = $this->get(route('cloud_import.google.start', $this->envelope))->assertRedirect();
    $location = (string) $response->headers->get('Location');
    $query = connectorsQueryFrom($response);

    expect($location)->toStartWith(GoogleOAuthClient::AUTHORIZE_URL.'?')
        ->and($location)->not->toContain('GOOGLE-CLIENT-SECRET-SENTINELA')
        ->and($query['scope'])->toBe(GoogleOAuthClient::SCOPE)
        ->and($query['response_type'])->toBe('code')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and(strlen($query['code_challenge']))->toBe(43)
        ->and(strlen($query['state']))->toBeGreaterThanOrEqual(43)
        ->and($query['access_type'])->toBe('online')
        ->and($query['client_id'])->toBe('google-client-id.apps.test')
        ->and($query['redirect_uri'])->toBe(route('cloud_import.google.callback'));

    Http::assertNothingSent();
});

test('retorno: troca o code com o code_verifier do desafio e guarda o token só na sessão, cifrado', function () {
    connectorsFakeGoogle();

    $query = connectorsQueryFrom($this->get(route('cloud_import.google.start', $this->envelope)));

    $this->get(route('cloud_import.google.callback', ['state' => $query['state'], 'code' => 'codigo-google']))
        ->assertRedirect(route('cloud_import.show', $this->envelope))
        ->assertSessionHas('success');

    Http::assertSent(function (Request $request) use ($query): bool {
        if ($request->url() !== GoogleOAuthClient::TOKEN_URL) {
            return false;
        }

        $data = $request->data();
        $verifier = (string) ($data['code_verifier'] ?? '');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return $request->method() === 'POST'
            && $data['grant_type'] === 'authorization_code'
            && $data['code'] === 'codigo-google'
            && $data['redirect_uri'] === route('cloud_import.google.callback')
            && strlen($verifier) >= 43 && strlen($verifier) <= 128
            && hash_equals($query['code_challenge'], $challenge);
    });

    // Na sessão só existe o valor CIFRADO; o refresh token nunca é guardado.
    $session = (string) json_encode(session()->all());
    expect($session)->not->toContain('ya29.SENTINELA-GOOGLE-ACCESS')
        ->and($session)->not->toContain('GOOGLE-REFRESH-SENTINELA')
        ->and(app(GoogleImportSession::class)->token($this->envelope, $this->owner))->toBe('ya29.SENTINELA-GOOGLE-ACCESS');

    // A página mostra "autorizado", e o token NÃO vai em prop (ficaria no histórico do navegador).
    $page = $this->get(route('cloud_import.show', $this->envelope));
    $page->assertInertia(fn (Assert $inertia) => $inertia
        ->component('integrations/cloud-import')
        ->where('providers.google_drive.available', true)
        ->where('providers.google_drive.authorized', true));
    expect((string) json_encode($page->viewData('page')))->not->toContain('SENTINELA-GOOGLE-ACCESS');
});

test('o token do Picker só sai por POST sem cache, e só dentro da janela autorizada', function () {
    connectorsFakeGoogle();

    $this->post(route('cloud_import.google.token', $this->envelope))->assertStatus(409);

    connectorsGoogleAuthorize($this, $this->envelope);

    $response = $this->post(route('cloud_import.google.token', $this->envelope))->assertOk();
    expect($response->json('access_token'))->toBe('ya29.SENTINELA-GOOGLE-ACCESS')
        ->and($response->json('api_key'))->toBe('google-picker-api-key')
        ->and($response->json())->not->toHaveKey('client_secret')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    $this->travel(11)->minutes();
    $this->post(route('cloud_import.google.token', $this->envelope))->assertStatus(409);
});

test('state reutilizado é recusado e o code não é trocado de novo', function () {
    connectorsFakeGoogle();

    $query = connectorsQueryFrom($this->get(route('cloud_import.google.start', $this->envelope)));
    $callback = route('cloud_import.google.callback', ['state' => $query['state'], 'code' => 'codigo-google']);

    $this->get($callback)->assertRedirect(route('cloud_import.show', $this->envelope));
    app(GoogleImportSession::class)->forget();

    $this->get($callback)
        ->assertRedirect(route('envelopes.index'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'expirou ou já foi usada'));

    Http::assertSentCount(1);
    expect(app(GoogleImportSession::class)->authorized($this->envelope, $this->owner))->toBeFalse();
});

test('state inventado, vencido ou iniciado por outro usuário é recusado sem falar com o Google', function () {
    Http::fake();

    $this->get(route('cloud_import.google.callback', ['state' => str_repeat('x', 43), 'code' => 'c']))
        ->assertRedirect(route('envelopes.index'));

    $query = connectorsQueryFrom($this->get(route('cloud_import.google.start', $this->envelope)));
    $this->travel(11)->minutes();
    $this->get(route('cloud_import.google.callback', ['state' => $query['state'], 'code' => 'c']))
        ->assertRedirect(route('envelopes.index'));
    $this->travelBack();

    $query = connectorsQueryFrom($this->get(route('cloud_import.google.start', $this->envelope)));
    $admin = attachMember($this->organization, MembershipRole::Admin);
    actingAsMember($admin, $this->organization);
    $this->get(route('cloud_import.google.callback', ['state' => $query['state'], 'code' => 'c']))
        ->assertRedirect(route('envelopes.index'));

    Http::assertNothingSent();
});

test('escopo não concedido ou autorização cancelada: nada fica guardado', function () {
    connectorsFakeGoogle([], [
        'access_token' => 'ya29.SENTINELA-GOOGLE-ACCESS',
        'expires_in' => 3599,
        'scope' => 'openid',
    ]);

    $query = connectorsQueryFrom($this->get(route('cloud_import.google.start', $this->envelope)));
    $this->get(route('cloud_import.google.callback', ['state' => $query['state'], 'code' => 'codigo-google']))
        ->assertRedirect(route('cloud_import.show', $this->envelope))
        ->assertSessionHas('error');

    $query = connectorsQueryFrom($this->get(route('cloud_import.google.start', $this->envelope)));
    $this->get(route('cloud_import.google.callback', ['state' => $query['state'], 'error' => 'access_denied']))
        ->assertRedirect(route('cloud_import.show', $this->envelope))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'cancelada'));

    expect(app(GoogleImportSession::class)->authorized($this->envelope, $this->owner))->toBeFalse();
    Http::assertSentCount(1);
});

test('sem o app registrado: estado honesto e nenhuma saída para o Google', function () {
    connectorsConfigure(google: false, dropbox: false);
    Http::fake();

    $this->get(route('cloud_import.show', $this->envelope))->assertInertia(fn (Assert $page) => $page
        ->component('integrations/cloud-import')
        ->where('providers.google_drive.available', false)
        ->where('providers.google_drive.picker', null)
        ->where('providers.google_drive.missing', ['GOOGLE_DRIVE_CLIENT_ID', 'GOOGLE_DRIVE_CLIENT_SECRET', 'GOOGLE_DRIVE_API_KEY', 'GOOGLE_DRIVE_APP_ID'])
        ->where('providers.dropbox.available', false)
        ->where('providers.dropbox.app_key', null)
        ->where('providers.dropbox.missing', ['DROPBOX_APP_KEY']));

    $this->get(route('cloud_import.google.start', $this->envelope))
        ->assertRedirect(route('cloud_import.show', $this->envelope))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'aguardando app registrado pelo proprietário'));

    Http::assertNothingSent();
});

test('a página de importação — e só ela — libera o Picker e o Chooser na CSP', function () {
    $import = $this->get(route('cloud_import.show', $this->envelope))->assertOk();
    $csp = (string) $import->headers->get('Content-Security-Policy');

    expect($csp)->toContain('https://apis.google.com')
        ->and($csp)->toContain('https://www.dropbox.com')
        ->and($csp)->toContain('frame-src https://docs.google.com')
        ->and($csp)->not->toContain("frame-src 'none'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and((string) $import->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin-allow-popups');

    $wizard = $this->get(route('envelopes.edit', $this->envelope))->assertOk();
    $wizardCsp = (string) $wizard->headers->get('Content-Security-Policy');

    expect($wizardCsp)->not->toContain('apis.google.com')
        ->and($wizardCsp)->toContain("frame-src 'none'");
});
