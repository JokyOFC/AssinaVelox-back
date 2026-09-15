<?php

use App\Enums\EnvelopeStatus;
use App\Http\Middleware\SecurityHeaders;
use App\Integrations\GoogleDrive\GoogleDriveSource;
use App\Integrations\GoogleDrive\GoogleOAuthClient;
use App\Models\CloudImport;
use App\Models\EmbeddedSigningSession;
use App\Models\Envelope;
use App\Services\Embed\AllowedOrigins;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Pest\Browser\ServerManager;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/Support/BrowserHelpers.php';
require_once __DIR__.'/../Feature/Support/OrganizationHelpers.php';
require_once __DIR__.'/../Feature/Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../Feature/Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../Feature/Finalization/Support/FinalizationHelpers.php';
require_once __DIR__.'/../Feature/Phase2/Api/Support/ApiHelpers.php';
require_once __DIR__.'/../Feature/Phase3/Connectors/Support/ConnectorHelpers.php';
require_once __DIR__.'/../Feature/Phase3/Embed/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| Ponta a ponta da onda G no navegador (integração I-3G)
|--------------------------------------------------------------------------
| O pedaço que só existe num navegador de verdade: o site do cliente (http://localhost:PORTA,
| outra ORIGEM que a do app em http://127.0.0.1:PORTA) carrega o embed.js, o iframe do widget
| abre a sessão embutida criada pela API v1 para essa origem, o participante assina dentro do
| iframe (código, desenho, confirmação visual) e o site recebe `assinavelox:completed`. O
| documento veio do Google Drive SIMULADO (Http::fake + DNS falso), o envelope conclui com o
| certificado de TESTE da operadora e o pdftool valida o PDF final.
*/

const WAVEG_BROWSER_CERT_PASS_ENV = 'WAVEG_BROWSER_CERT_PASS';
const WAVEG_BROWSER_DRIVE_FILE = '1WaveGBrowserDrivePdf';

beforeEach(function () {
    $this->work = browserWorkspace();
    browserDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('pdftool.trust_roots', []);
    fakeEmailProvider();
    $this->notifications = browserCaptureNotifications();

    // `/_teste/*` faz o papel do SITE DO CLIENTE: fora da CSP do app (que proíbe iframe e script
    // inline). Todo o resto continua com o middleware real.
    app()->bind(SecurityHeaders::class, fn () => new class extends SecurityHeaders
    {
        public function handle(Request $request, Closure $next): Response
        {
            return $request->is('_teste/*') ? $next($request) : parent::handle($request, $next);
        }
    });

    ServerManager::instance()->http()->bootstrap();
    $this->appOrigin = rtrim(ServerManager::instance()->http()->rewrite('/'), '/');
    $this->hostOrigin = str_replace('://127.0.0.1', '://localhost', $this->appOrigin);

    putenv(WAVEG_BROWSER_CERT_PASS_ENV.'=senha-de-teste-Wgb51!');
    $this->certificate = TestCertificate::generate($this->work.DIRECTORY_SEPARATOR.'certs', WAVEG_BROWSER_CERT_PASS_ENV, TestCertificate::SUBJECT, 2);
    TestCertificate::configure($this->certificate);
    TestCertificate::register($this->certificate);
});

afterEach(function () {
    putenv(WAVEG_BROWSER_CERT_PASS_ENV);
    browserCleanup($this->work ?? null);
});

/** Página do site do cliente: carrega o embed.js e registra os eventos recebidos. */
function waveGBrowserHostRoute(string $url): void
{
    $json = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    Route::get('/_teste/onda-g-host', fn () => response(<<<HTML
<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><title>Portal do cliente</title></head>
<body>
<h1>Portal do cliente</h1>
<div id="widget" style="width: 900px"></div>
<ul id="events"></ul>
<script src="/embed/v1/embed.js"></script>
<script>
window.__events = [];
function log(kind, detail) {
    window.__events.push({ kind: kind, detail: detail });
    var item = document.createElement('li');
    item.textContent = kind + ':' + (detail.screen || detail.code || detail.status || '');
    document.getElementById('events').appendChild(item);
}
AssinaVelox.mount({
    url: {$json},
    container: '#widget',
    readyTimeout: 8000,
    onReady: function (d) { log('ready', d); },
    onCompleted: function (d) { log('completed', d); },
    onRefused: function (d) { log('refused', d); },
    onError: function (d) { log('error', d); }
});
</script>
</body>
</html>
HTML));
}

/** @return list<string> */
function waveGBrowserEventKinds(object $page): array
{
    $kinds = $page->script('window.__events.map(function (e) { return e.kind; })');

    return is_array($kinds) ? array_values($kinds) : [];
}

it('site do cliente: widget da sessão criada pela API sobre um PDF do Drive, assinatura no iframe, assinavelox:completed e PDF final validado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Onda G (navegador)']);
    setPlanQuota($organization, 10);
    finalizationEnableCompanySignature($organization);
    embedEnable($organization);
    connectorsEnable($organization, true, false);
    connectorsDns();
    connectorsConfigure(hubspot: false);

    $pdf = (string) file_get_contents(browserPdf($this->work.DIRECTORY_SEPARATOR.'drive.pdf', 'Contrato de prestação — onda G'));

    Http::preventStrayRequests();
    Http::fake(function (HttpRequest $request) use ($pdf) {
        $url = $request->url();

        return match (true) {
            $url === GoogleOAuthClient::TOKEN_URL => Http::response(['access_token' => 'ya29.SENTINELA-NAVEGADOR', 'expires_in' => 3599, 'scope' => GoogleOAuthClient::SCOPE, 'token_type' => 'Bearer']),
            $url === GoogleOAuthClient::REVOKE_URL => Http::response('', 200),
            str_starts_with($url, rtrim(GoogleDriveSource::FILES_URL, '/').'/'.WAVEG_BROWSER_DRIVE_FILE) => str_contains($url, 'alt=media')
                ? Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])
                : Http::response(['id' => WAVEG_BROWSER_DRIVE_FILE, 'name' => 'Contrato.pdf', 'mimeType' => 'application/pdf', 'size' => (string) strlen($pdf)]),
            default => Http::response('inesperado', 500),
        };
    });

    // -- API v1: rascunho; painel: importação do Drive simulado; API: participante, campo, envio --
    $token = apiIssueToken($organization, $owner);
    $id = (string) $this->postJson('/api/v1/envelopes', ['title' => 'Prestação — navegador'], apiHeaders($token, apiIdem()))->assertCreated()->json('data.id');
    $envelope = Envelope::withoutOrganizationScope()->where('ulid', $id)->sole();

    config()->set('auth.defaults.guard', 'web');
    app('auth')->forgetGuards();
    actingAsMember($owner, $organization);
    connectorsGoogleAuthorize($this, $envelope);
    $this->post(route('cloud_import.google.store', $envelope), ['file_ids' => [WAVEG_BROWSER_DRIVE_FILE]])->assertRedirect(route('envelopes.edit', $envelope));
    expect(CloudImport::withoutOrganizationScope()->sole()->status)->toBe(CloudImport::STATUS_COMPLETED);

    $headers = apiHeaders($token);
    $documents = $this->getJson('/api/v1/envelopes/'.$id, $headers)->assertOk()->json('data.documents');
    $recipients = $this->putJson('/api/v1/envelopes/'.$id.'/recipients', [
        'signing_order' => 'sequential',
        'recipients' => [['name' => 'Carla Cliente Final', 'email' => 'carla@cliente.example.com.br']],
    ], $headers)->assertOk()->json('data');
    $this->putJson('/api/v1/envelopes/'.$id.'/fields', ['fields' => [
        ['recipient_id' => $recipients[0]['id'], 'document_id' => $documents[0]['id'], 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06, 'required' => true],
    ]], $headers)->assertOk();
    $this->postJson('/api/v1/envelopes/'.$id.'/send', [], apiHeaders($token, apiIdem()))->assertOk();

    // -- Origem do site do cliente e sessão embutida pela API ------------------------------
    AllowedOrigins::replace($organization, [$this->hostOrigin]);
    $scenario = ['envelope' => $envelope, 'recipient' => $envelope->recipients()->sole(), 'api_token' => $token];
    $apiUrl = (string) embedCreate($this, $scenario, ['origin' => $this->hostOrigin])->assertCreated()->json('data.url');
    $sessionId = embedSessionIdFrom($apiUrl);

    waveGBrowserHostRoute($this->appOrigin.'/embed/v1/sessoes/'.$sessionId.'#t='.embedTokenFrom($apiUrl));

    // -- No navegador: o site do cliente, o widget e a assinatura -------------------------
    $codes = $this->notifications['codes'];
    $page = visit($this->hostOrigin.'/_teste/onda-g-host');

    $page->assertSee('ready:identify');

    $inFrame = 'iframe >> internal:control=enter-frame >> ';
    $page->click($inFrame.'internal:role=button[name="Receber código por e-mail"s]');

    browserWaitFor(fn () => count($codes) > 0, 'o código ser enviado');

    $page->type($inFrame.'input[aria-label^="Código de "]', $codes[count($codes) - 1]);

    $page->withinFrame('iframe', function ($frame): void {
        $frame->assertSee('Sua assinatura');
        browserDrawOnCanvas($frame, "document.querySelector('canvas[aria-label=\"Quadro para desenhar a assinatura\"]')");
        $frame->assertSee('Pronta');
    });

    $page->click($inFrame.'button[role=checkbox]');
    $page->click($inFrame.'internal:role=button[name="Assinar documento"s]');
    $page->click($inFrame.'internal:role=button[name="Confirmar e assinar"s]');

    $page->assertSee('completed:');

    $completed = browserWaitFor(
        fn () => $envelope->fresh()->status === EnvelopeStatus::Completed ? $envelope->fresh() : null,
        'o envelope concluir (finalização com o pdftool)',
        60.0,
    );

    expect(waveGBrowserEventKinds($page))->toBe(['ready', 'completed'])
        ->and(EmbeddedSigningSession::withoutGlobalScopes()->where('ulid', $sessionId)->value('outcome'))->toBe('completed');

    // -- O pdftool valida o PDF final ---------------------------------------------------------
    $final = finalizationDownload($completed->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
    $validation = app(PdfToolClient::class)->validate($final, [$this->certificate['pem']]);

    expect($validation->signatureCount)->toBe(1)
        ->and($validation->allIntact)->toBeTrue()
        ->and($validation->allValid)->toBeTrue();
});
