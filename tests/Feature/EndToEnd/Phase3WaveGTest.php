<?php

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Integrations\GoogleDrive\GoogleDriveSource;
use App\Integrations\GoogleDrive\GoogleOAuthClient;
use App\Models\AuditEvent;
use App\Models\CloudImport;
use App\Models\EmbeddedSigningSession;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\SignatureAcceptance;
use App\Models\User;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Services\Embed\AllowedOrigins;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Feature\Documents\Support\DocumentFixtures;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Documents/Support/helpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../Finalization/Support/FinalizationHelpers.php';
require_once __DIR__.'/../Phase2/Api/Support/ApiHelpers.php';
require_once __DIR__.'/../Phase3/Sso/Support/SsoHelpers.php';
require_once __DIR__.'/../Phase3/Connectors/Support/ConnectorHelpers.php';
require_once __DIR__.'/../Phase3/Embed/Support/EmbedHelpers.php';
require_once __DIR__.'/../Phase3/Sdk/Support/OpenApiContract.php';

/*
|--------------------------------------------------------------------------
| Ponta a ponta da Fase 3 — onda G (integração I-3G)
|--------------------------------------------------------------------------
| Com as cinco flags da onda ligadas (interruptor global E plano), pelas rotas HTTP reais e com
| TODOS os provedores simulados (nenhuma chamada de rede: Http::fake + preventStrayRequests e
| DNS falso):
|
|  uma pessoa entra por OIDC (IdP simulado, JIT como `member`) na Horizonte e outra por SAML
|  (IdP simulado, assertion assinada) na Parceira → a API v1 (token da organização) cria o
|  rascunho → o owner importa um PDF REAL do Google Drive simulado (OAuth + PKCE, token
|  revogado ao terminar) → a API define participante e campo e envia → o owner cadastra a
|  origem do site do cliente na tela → a API cria a sessão embutida para essa origem → o widget
|  troca a URL de uso único, confirma o código, apresenta o PDF e registra o aceite com a
|  confirmação visual → a finalização conclui com o pdftool REAL e o certificado de TESTE da
|  operadora → o pdftool valida o PDF final → a resposta REAL de `GET /envelopes/{id}` segue
|  sdks/openapi/v1.json e o SDK Python a lê (servidor local de teste que devolve esse corpo).
|
| O aviso `assinavelox:completed` ao site hospedeiro (postMessage) só existe num navegador: está
| em tests/Browser/Phase3WaveGTest.php.
|
| Um segundo caso percorre o fluxo antigo (API v1 da Fase 2 + página pública) com as cinco
| flags DESLIGADAS: rotas novas 404, cabeçalhos anti-iframe intactos, nenhuma linha nas
| tabelas novas e nenhum evento novo na trilha.
*/

const WAVEG_CERT_PASS_ENV = 'WAVEG_TEST_CERT_PASS';
const WAVEG_PARTNER_DOMAIN = 'parceira.com.br';
const WAVEG_DRIVE_FILE = '1WaveGDriveContratoPdf';

/** @var list<string> */
const WAVEG_FLAGS = ['embedded_signing', 'sso_oidc', 'sso_saml', 'cloud_import', 'hubspot'];

/** @var list<string> */
const WAVEG_TABLES = [
    'embedded_signing_sessions', 'integration_settings', 'sso_connections', 'sso_domains', 'sso_identities',
    'sso_saml_requests', 'sso_consumed_assertions', 'cloud_imports', 'hubspot_connections', 'hubspot_action_executions',
];

/** @var list<string> */
const WAVEG_EVENT_PREFIXES = ['sso.', 'embedded_session.', 'embed_origins.', 'cloud_import.', 'hubspot.'];

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->withoutVite();
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('pdftool.trust_roots', []);
    config()->set('inertia.ssr.enabled', false);
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
    fakeEmailProvider();
    Http::preventStrayRequests();

    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Horizonte Onda G']);
    setPlanQuota($this->organization, 10);

    $this->codes = signerCaptureCodes();
    $this->invites = new ArrayObject;
    $invites = $this->invites;
    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($invites): void {
        if ($event->notification instanceof RecipientInvitationNotification) {
            $invites[$event->notification->recipient->email] = $event->notification->signingUrl;
        }
    });

    // Certificado A1 de TESTE da operadora (nunca de produção) e o item no plano.
    putenv(WAVEG_CERT_PASS_ENV.'=senha-de-teste-Wg37!');
    $this->certificate = TestCertificate::generate($this->work.DIRECTORY_SEPARATOR.'certs', WAVEG_CERT_PASS_ENV, TestCertificate::SUBJECT, 2);
    TestCertificate::configure($this->certificate);
    TestCertificate::register($this->certificate);
    finalizationEnableCompanySignature($this->organization);
});

afterEach(function () {
    putenv(WAVEG_CERT_PASS_ENV);
    PdfFixtures::cleanup($this->work ?? null);
});

/**
 * Volta ao painel depois de chamadas à API. No teste a aplicação é a MESMA entre requisições e o
 * guard da API (`shouldUse('sanctum')`) fica como padrão: a próxima tela do painel veria um
 * visitante. Em produção cada requisição começa do zero — isto é só do ambiente de teste.
 */
function waveGPanel(object $test, User $user): void
{
    config()->set('auth.defaults.guard', 'web');
    app('auth')->forgetGuards();
    actingAsMember($user, $test->organization);
}

/** Liga as cinco flags da onda: interruptor global E o plano (compartilhado pelas organizações). */
function waveGEnableAll(object $test): void
{
    apiEnable($test->organization);
    ssoEnable($test->organization, true, true);
    connectorsEnable($test->organization, true, true);
    embedEnable($test->organization);
}

/**
 * Provedores simulados num único Http::fake: IdP OIDC (discovery, JWKS, token) e Google (token,
 * revogação e Drive v3). Um host desconhecido responde 500 — e o DNS falso nem o resolve.
 *
 * @param  array{private: string, public: string, jwk: array<string, string>}  $key
 */
function waveGFakeProviders(ArrayObject $oidc, array $key, string $pdf): void
{
    Http::fake(function (HttpRequest $request) use ($oidc, $key, $pdf) {
        $url = $request->url();

        if ($url === SSO_ISSUER.'/.well-known/openid-configuration') {
            return Http::response(ssoDiscovery());
        }

        if ($url === SSO_ISSUER.'/jwks') {
            return Http::response(['keys' => [$key['jwk']]]);
        }

        if ($url === SSO_ISSUER.'/token') {
            return Http::response([
                'token_type' => 'Bearer',
                'access_token' => 'oidc-access-token-onda-g',
                'id_token' => ssoIdToken(ssoClaims((string) $oidc['nonce'], (array) $oidc['claims']), $key['private']),
            ]);
        }

        if ($url === GoogleOAuthClient::TOKEN_URL) {
            return Http::response([
                'access_token' => 'ya29.SENTINELA-ONDA-G',
                'expires_in' => 3599,
                'scope' => GoogleOAuthClient::SCOPE,
                'token_type' => 'Bearer',
            ]);
        }

        if ($url === GoogleOAuthClient::REVOKE_URL) {
            return Http::response('', 200);
        }

        if (str_starts_with($url, rtrim(GoogleDriveSource::FILES_URL, '/').'/'.WAVEG_DRIVE_FILE)) {
            return str_contains($url, 'alt=media')
                ? Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])
                : Http::response(['id' => WAVEG_DRIVE_FILE, 'name' => 'Contrato de prestação — Drive.pdf', 'mimeType' => 'application/pdf', 'size' => (string) strlen($pdf)]);
        }

        return Http::response('inesperado: '.$url, 500);
    });
}

/**
 * O SDK Python (sdks/python) lê o envelope num servidor LOCAL de teste que devolve o corpo REAL
 * da API. O token vai pelo ambiente, nunca pela linha de comando.
 *
 * @return array<string, mixed>
 */
function waveGPythonSdkRead(string $work, string $envelopeId, string $body, string $token): array
{
    $bodyFile = $work.DIRECTORY_SEPARATOR.'envelope.json';
    $script = $work.DIRECTORY_SEPARATOR.'sdk_read.py';
    file_put_contents($bodyFile, $body);
    file_put_contents($script, <<<'PY'
import json, os, sys, threading
from http.server import BaseHTTPRequestHandler, HTTPServer

sys.path.insert(0, os.environ["SDK_PATH"])
from assinavelox import AssinaVelox

body = open(os.environ["BODY_FILE"], "rb").read()
envelope_id = os.environ["ENVELOPE_ID"]
token = os.environ["API_TOKEN"]
seen = []

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        seen.append({"path": self.path, "auth_ok": self.headers.get("Authorization") == "Bearer " + token, "ua": self.headers.get("User-Agent", "")})
        if self.path != "/api/v1/envelopes/" + envelope_id or not seen[-1]["auth_ok"]:
            problem = b'{"type":"about:blank","title":"Not Found","status":404}'
            self.send_response(404)
            self.send_header("Content-Type", "application/problem+json")
            self.send_header("Content-Length", str(len(problem)))
            self.end_headers()
            self.wfile.write(problem)
            return
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

server = HTTPServer(("127.0.0.1", 0), Handler)
threading.Thread(target=server.serve_forever, daemon=True).start()
try:
    client = AssinaVelox(base_url="http://127.0.0.1:%d/api/v1" % server.server_address[1], token=token)
    envelope = client.get_envelope(envelope_id)
finally:
    server.shutdown()

print(json.dumps({
    "id": envelope.id,
    "status": envelope.status,
    "status_label": envelope.status_label,
    "signed_count": envelope.signed_count,
    "documents": len(envelope.documents or []),
    "token_in_repr": token in repr(client),
    "requests": seen,
}))
PY);

    $process = new Process([PdfFixtures::pythonBinary(), $script], base_path(), [
        'SDK_PATH' => base_path('sdks/python'),
        'BODY_FILE' => $bodyFile,
        'ENVELOPE_ID' => $envelopeId,
        'API_TOKEN' => $token,
        'PYTHONIOENCODING' => 'utf-8',
        'NO_PROXY' => '127.0.0.1,localhost',
        'no_proxy' => '127.0.0.1,localhost',
    ]);
    $process->setTimeout(60);
    $process->run();

    expect($process->getExitCode())->toBe(0, "SDK Python falhou:\n".$process->getOutput()."\n".$process->getErrorOutput());

    return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<string> */
function waveGNewEventTypes(): array
{
    return AuditEvent::withoutGlobalScopes()->pluck('event_type')
        ->map(fn ($type): string => is_string($type) ? $type : $type->value)
        ->filter(fn (string $type): bool => collect(WAVEG_EVENT_PREFIXES)->contains(fn (string $prefix): bool => str_starts_with($type, $prefix)))
        ->values()
        ->all();
}

it('SSO OIDC e SAML → API cria o rascunho → PDF do Drive → envio → sessão embutida → aceite no widget → conclusão, pdftool, especificação e SDK Python', function () {
    waveGEnableAll($this);

    // DNS falso único: IdP e Google resolvem para endereços públicos; nada mais resolve.
    connectorsDns(['idp.example.com' => [SSO_PUBLIC_IP]]);
    connectorsConfigure(hubspot: false);

    $pdf = (string) file_get_contents(PdfFixtures::onePagePdf($this->work.DIRECTORY_SEPARATOR.'drive-original.pdf', 'Contrato de prestação — onda G'));
    $key = ssoRsaKey('k1');
    $oidc = new ArrayObject(['nonce' => '', 'claims' => []]);
    waveGFakeProviders($oidc, $key, $pdf);

    // -- 1. Login corporativo por OIDC na Horizonte: JIT como `member` -------------------------
    ssoVerifiedDomain($this->organization, SSO_DOMAIN);
    $oidcConnection = ssoOidcConnection($this->organization, ['jit_provisioning' => true]);

    $params = ssoStartOidc($this, 'ana@'.SSO_DOMAIN);
    $oidc['nonce'] = $params['nonce'];
    $oidc['claims'] = ['sub' => 'ana-sujeito-1', 'email' => 'ana@'.SSO_DOMAIN, 'name' => 'Ana Operadora'];

    $this->get(route('sso.oidc.callback', ['connection' => $oidcConnection->ulid, 'state' => $params['state'], 'code' => 'codigo-onda-g']))
        ->assertRedirect(config('fortify.home'));

    $ana = User::query()->where('email', 'ana@'.SSO_DOMAIN)->sole();
    $this->assertAuthenticatedAs($ana);

    $anaMembership = Membership::query()->where('user_id', $ana->id)->sole();
    expect($anaMembership->role)->toBe(MembershipRole::Member)
        ->and($anaMembership->organization_id)->toBe($this->organization->id)
        ->and($anaMembership->getAttribute('auth_via'))->toBe('sso')
        ->and(ssoLastAudit($this->organization, 'sso.user_provisioned'))->toMatchArray(['role' => 'member', 'new_account' => true])
        ->and(ssoLastAudit($this->organization, 'sso.login_succeeded'))->toMatchArray(['protocol' => 'oidc', 'domain' => SSO_DOMAIN]);

    // -- 2. Login corporativo por SAML em outra organização (uma conexão por organização) -----
    $this->post(route('logout'));
    $this->flushSession();
    app('auth')->forgetGuards();
    $this->assertGuest();

    ['organization' => $partner] = createOrganizationWithOwner(['name' => 'Parceira SAML']);
    ssoVerifiedDomain($partner, WAVEG_PARTNER_DOMAIN);
    $idp = ssoCertificate();
    $samlConnection = ssoSamlConnection($partner, $idp['certificate']);
    $bruno = ssoMember($partner, 'bruno@'.WAVEG_PARTNER_DOMAIN);

    $start = ssoStartSaml($this, 'bruno@'.WAVEG_PARTNER_DOMAIN);
    $xml = ssoSamlSign(ssoSamlXml($samlConnection, ['in_response_to' => $start['request_id'], 'email' => 'bruno@'.WAVEG_PARTNER_DOMAIN]), $idp);
    ssoPostAcs($this, $samlConnection, $xml, $start['binding'])->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($bruno);
    expect(ssoLastAudit($partner, 'sso.login_succeeded'))->toMatchArray(['protocol' => 'saml', 'domain' => WAVEG_PARTNER_DOMAIN]);

    $this->post(route('logout'));
    $this->flushSession();
    app('auth')->forgetGuards();

    // -- 3. A API v1 (token da organização) cria o rascunho ----------------------------------
    $token = apiIssueToken($this->organization, $this->owner);
    $headers = apiHeaders($token);

    $created = $this->postJson('/api/v1/envelopes', ['title' => 'Prestação de serviços — onda G', 'expires_in_days' => 10], apiHeaders($token, apiIdem()))
        ->assertCreated();
    $id = (string) $created->json('data.id');
    $envelope = Envelope::withoutOrganizationScope()->where('ulid', $id)->sole();

    // -- 4. O owner importa o PDF do Google Drive simulado no rascunho criado pela API -------
    waveGPanel($this, $this->owner);
    $this->get(route('cloud_import.show', $envelope))->assertOk();
    connectorsGoogleAuthorize($this, $envelope);

    $this->post(route('cloud_import.google.store', $envelope), ['file_ids' => [WAVEG_DRIVE_FILE]])
        ->assertRedirect(route('envelopes.edit', $envelope))
        ->assertSessionHas('success');

    $import = CloudImport::withoutOrganizationScope()->sole();
    expect($import->status)->toBe(CloudImport::STATUS_COMPLETED)
        ->and($import->sha256)->toBe(hash('sha256', $pdf))
        ->and($import->simulated)->toBeFalse()
        ->and($import->imported_by_user_id)->toBe($this->owner->id);

    // Nenhum token além da importação: revogado no Google.
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === GoogleOAuthClient::REVOKE_URL
        && ($request->data()['token'] ?? null) === 'ya29.SENTINELA-ONDA-G');

    // -- 5. A API vê o documento importado, define participante e campo e envia --------------
    $documents = $this->getJson('/api/v1/envelopes/'.$id, $headers)->assertOk()->json('data.documents');
    expect($documents)->toHaveCount(1)
        ->and($documents[0]['processing_status'])->toBe('ready');

    $recipients = $this->putJson('/api/v1/envelopes/'.$id.'/recipients', [
        'signing_order' => 'sequential',
        'recipients' => [['name' => 'Carla Cliente Final', 'email' => 'carla@cliente.example.com.br']],
    ], $headers)->assertOk()->json('data');

    $this->putJson('/api/v1/envelopes/'.$id.'/fields', ['fields' => [
        ['recipient_id' => $recipients[0]['id'], 'document_id' => $documents[0]['id'], 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06, 'required' => true],
    ]], $headers)->assertOk();

    $this->postJson('/api/v1/envelopes/'.$id.'/send', [], apiHeaders($token, apiIdem()))
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');

    // O convite por e-mail continua saindo: o widget é um caminho A MAIS.
    expect(isset($this->invites['carla@cliente.example.com.br']))->toBeTrue();

    // -- 6. O owner cadastra a origem do site do cliente; a API cria a sessão embutida -------
    waveGPanel($this, $this->owner);
    $this->get(route('integrations.embed.edit'))->assertOk();
    $this->put(route('integrations.embed.update'), ['origins' => [EMBED_ORIGIN]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect(AllowedOrigins::forOrganization($this->organization))->toBe([EMBED_ORIGIN])
        ->and(ssoLastAudit($this->organization, 'embed_origins.updated'))->toMatchArray(['added' => [EMBED_ORIGIN], 'count' => 1]);

    $recipient = $envelope->recipients()->sole();
    $scenario = ['envelope' => $envelope, 'recipient' => $recipient, 'api_token' => $token];

    // Origem fora da lista: recusada.
    $this->postJson(embedCreateUrl($scenario), ['origin' => 'https://intruso.example.com'], apiHeaders($token, apiIdem()))
        ->assertStatus(422);

    $sessionUrl = (string) embedCreate($this, $scenario)->assertCreated()->json('data.url');
    $sessionId = embedSessionIdFrom($sessionUrl);

    // A página do widget só pode ser enquadrada pela origem EXATA da sessão.
    $page = $this->get(route('embed.show', ['session' => $sessionId]))->assertOk();
    expect($page->headers->has('X-Frame-Options'))->toBeFalse()
        ->and((string) $page->headers->get('Content-Security-Policy'))->toContain('frame-ancestors '.EMBED_ORIGIN)
        ->and($page->headers->getCookies())->toBe([]);

    // O resto do app continua DENY.
    expect($this->get(route('dashboard'))->headers->get('X-Frame-Options'))->toBe('DENY');

    // -- 7. O participante assina pelo widget (sem cookie: token só no cabeçalho) ------------
    $this->flushSession();
    app('auth')->forgetGuards();

    $runtime = (string) $this->postJson(route('embed.exchange', ['session' => $sessionId]), ['token' => embedTokenFrom($sessionUrl)])
        ->assertOk()->json('token');
    $open = ['id' => $sessionId, 'runtime' => $runtime, 'url' => $sessionUrl];

    $state = embedAuthenticate($this, $open);
    expect($state['screen'])->toBe('sign');

    $this->get($state['documents'][0]['pdf_url'], embedHeaders($runtime))->assertOk();

    $this->postJson(route('embed.complete', ['session' => $sessionId]), embedAcceptancePayload($state), embedHeaders($runtime))
        ->assertOk()
        ->assertJsonPath('message', 'Aceite registrado.');

    $envelope->refresh();
    $acceptance = SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $recipient->id)->sole();
    $embedded = EmbeddedSigningSession::withoutGlobalScopes()->where('ulid', $sessionId)->sole();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($embedded->outcome)->toBe('completed')
        ->and($acceptance->consent_statement)->not->toBeEmpty();

    // O PDF apresentado pelo widget fica marcado com o canal e a sessão embutida.
    $presented = AuditEvent::withoutGlobalScopes()->where('envelope_id', $envelope->id)->where('event_type', 'document.presented')->latest('id')->first();
    expect($presented?->payload)->toMatchArray(['channel' => 'embedded']);

    foreach (['cloud_import.completed', 'embedded_session.created', 'embedded_session.opened'] as $type) {
        expect(AuditEvent::withoutGlobalScopes()->where('envelope_id', $envelope->id)->where('event_type', $type)->exists())->toBeTrue("evento {$type} ausente");
    }

    // T1: a sessão do painel (mesmo vinda do SSO) não abre o widget — só o token do participante.
    $this->actingAs($ana);
    $this->getJson(route('embed.state', ['session' => $sessionId]))->assertUnauthorized();
    app('auth')->forgetGuards();

    // -- 8. O pdftool valida o arquivo final -------------------------------------------------
    $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final-onda-g.pdf');
    $validation = app(PdfToolClient::class)->validate($final, [$this->certificate['pem']]);

    expect($validation->signatureCount)->toBe(1)
        ->and($validation->allIntact)->toBeTrue()
        ->and($validation->allValid)->toBeTrue()
        ->and($validation->allTrusted())->toBeTrue()
        ->and($validation->signatures[0]->coverage)->toBe('ENTIRE_FILE');

    // -- 9. A resposta real segue a especificação; o SDK Python a lê ----------------------------
    $show = $this->getJson('/api/v1/envelopes/'.$id, $headers)->assertOk()->assertJsonPath('data.status', 'completed');
    sdkAssertContract('v1.envelopes.show', $show);

    $sdk = waveGPythonSdkRead($this->work, $id, (string) $show->getContent(), $token);

    expect($sdk['id'])->toBe($id)
        ->and($sdk['status'])->toBe('completed')
        ->and($sdk['status_label'])->toBe($show->json('data.status_label'))
        ->and($sdk['signed_count'])->toBe(1)
        ->and($sdk['documents'])->toBe(1)
        ->and($sdk['token_in_repr'])->toBeFalse()
        ->and($sdk['requests'])->toHaveCount(1)
        ->and($sdk['requests'][0]['auth_ok'])->toBeTrue()
        ->and($sdk['requests'][0]['ua'])->toStartWith('assinavelox-python/');

    // -- 10. Nenhum segredo em trilha: client secret, tokens OAuth e token da sessão ------------
    $trail = json_encode(AuditEvent::withoutGlobalScopes()->pluck('payload')->all());
    foreach ([SSO_CLIENT_SECRET, 'oidc-access-token-onda-g', 'ya29.SENTINELA-ONDA-G', 'GOOGLE-CLIENT-SECRET-SENTINELA', embedTokenFrom($sessionUrl), $runtime] as $secret) {
        expect($trail)->not->toContain($secret);
    }
})->group('slow');

it('com as cinco flags desligadas, o fluxo antigo (API v1 + página pública) não muda: rotas novas 404, DENY, nada gravado', function () {
    // Só o que já existia antes da onda G: a API v1 da Fase 2.
    apiEnable($this->organization);

    // Rotas públicas novas, para quem ainda não entrou: 404 (nenhuma chamada sai).
    $this->get(route('embed.script'))->assertNotFound();
    $this->post(route('sso.login.start'), ['email' => 'ana@'.SSO_DOMAIN])->assertNotFound();
    $this->postJson(route('webhooks.hubspot.action'), ['callbackId' => 'x'])->assertNotFound();
    $this->get(route('login'))->assertOk()->assertHeader('X-Frame-Options', 'DENY');

    actingAsMember($this->owner, $this->organization);

    $shared = $this->get(route('dashboard'))->assertHeader('X-Frame-Options', 'DENY')->viewData('page')['props']['features'];
    foreach (WAVEG_FLAGS as $flag) {
        expect($shared[$flag])->toBeFalse("a flag {$flag} deveria estar desligada");
    }

    $token = apiIssueToken($this->organization, $this->owner);
    $headers = apiHeaders($token);

    $created = $this->postJson('/api/v1/envelopes', ['title' => 'Prestação sem onda G', 'expires_in_days' => 10], apiHeaders($token, apiIdem()))
        ->assertCreated();
    $id = (string) $created->json('data.id');
    $envelope = Envelope::withoutOrganizationScope()->where('ulid', $id)->sole();

    // Rotas novas do painel: 404.
    waveGPanel($this, $this->owner);
    $this->get(route('cloud_import.show', $envelope))->assertNotFound();
    $this->get(route('integrations.hubspot.show'))->assertNotFound();
    $this->get(route('integrations.embed.edit'))->assertNotFound();
    $this->get(route('settings.sso'))->assertNotFound();

    // Upload pela API, como na Fase 2.
    $original = PdfFixtures::onePagePdf($this->work.DIRECTORY_SEPARATOR.'original.pdf', 'Contrato sem onda G');
    $this->post('/api/v1/envelopes/'.$id.'/documents', ['file' => DocumentFixtures::upload($original, 'contrato.pdf', 'application/pdf')], apiHeaders($token, apiIdem()))
        ->assertCreated()
        ->assertJsonPath('data.processing_status', 'ready');

    $documents = $this->getJson('/api/v1/envelopes/'.$id, $headers)->assertOk()->json('data.documents');
    $recipients = $this->putJson('/api/v1/envelopes/'.$id.'/recipients', [
        'signing_order' => 'sequential',
        'recipients' => [['name' => 'Carla Cliente Final', 'email' => 'carla@cliente.example.com.br']],
    ], $headers)->assertOk()->json('data');
    $this->putJson('/api/v1/envelopes/'.$id.'/fields', ['fields' => [
        ['recipient_id' => $recipients[0]['id'], 'document_id' => $documents[0]['id'], 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06, 'required' => true],
    ]], $headers)->assertOk();

    // A sessão embutida não existe: 404 (a ability nova nem abre a rota).
    $this->postJson(embedCreateUrl(['envelope' => $envelope, 'recipient' => $envelope->recipients()->sole()]), ['origin' => EMBED_ORIGIN], apiHeaders($token, apiIdem()))
        ->assertNotFound();

    $this->postJson('/api/v1/envelopes/'.$id.'/send', [], apiHeaders($token, apiIdem()))->assertOk();

    $this->flushSession();
    app('auth')->forgetGuards();

    // Assinatura pela página pública, exatamente como antes.
    $signToken = collect(explode('/', (string) parse_url((string) $this->invites['carla@cliente.example.com.br'], PHP_URL_PATH)))->filter()->last();
    // A página pública continua proibindo iframe (antes de entrar: recarregar depois do código
    // renovaria a autorização da tela).
    expect($this->get(route('sign.show', ['token' => $signToken]))->headers->get('X-Frame-Options'))->toBe('DENY');

    $props = authenticateSigner($this, (string) $signToken);

    $this->post(route('sign.complete', ['token' => $signToken]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
        'fields' => [],
    ])->assertSessionHasNoErrors();

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final-sem-onda-g.pdf');
    $validation = app(PdfToolClient::class)->validate($final, [$this->certificate['pem']]);
    expect($validation->signatureCount)->toBe(1)
        ->and($validation->allIntact)->toBeTrue()
        ->and($validation->allValid)->toBeTrue();

    // O `document.presented` do fluxo antigo não ganha canal novo.
    $presented = AuditEvent::withoutGlobalScopes()->where('envelope_id', $envelope->id)->where('event_type', 'document.presented')->latest('id')->first();
    expect($presented?->payload ?? [])->not->toHaveKey('channel');

    // Nenhuma linha nas tabelas novas; nenhum evento novo na trilha; membership sem SSO.
    foreach (WAVEG_TABLES as $table) {
        expect(DB::table($table)->count())->toBe(0, "a tabela {$table} deveria continuar vazia");
    }

    expect(waveGNewEventTypes())->toBe([])
        ->and(Membership::query()->where('organization_id', $this->organization->id)->pluck('auth_via')->filter()->unique()->values()->all())->not->toContain('sso')
        ->and(Membership::query()->whereNotNull('last_sso_login_at')->exists())->toBeFalse();

    Http::assertNothingSent();
    Carbon::setTestNow();
})->group('slow');
