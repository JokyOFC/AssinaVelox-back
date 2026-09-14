<?php

use App\Enums\AuditEventType;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\TransportResult;
use App\Services\Webhooks\WebhookSignature;
use App\Services\Webhooks\WebhookTransport;
use App\Support\Http\BlockedOutboundUrl;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\Support\Https\HttpsTestServer;

require_once __DIR__.'/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Pino de IP em HTTPS (viabilidade §7 item 3; complemento de PinnedConnectionTest)
|--------------------------------------------------------------------------
|
| Teste de INTEGRAÇÃO com TLS real, sem sair da máquina. Em tempo de teste gera uma AC de teste
| e certificados de servidor (cryptography do venv do pdftool) e sobe um servidor HTTPS mínimo
| (ssl do Python) em 127.0.0.1:<porta livre>. O nome é `hooks.assinavelox.test` — TLD reservado
| (RFC 2606) que o DNS do sistema NÃO resolve: se a conexão acontece, foi pelo endereço pinado.
|
| Confiança: o transporte fixa `verify => true` (e o Guzzle 8.2 recusa CURLOPT_CAINFO em
| `curl`). Um middleware global SÓ DE TESTE troca `verify: true` pelo caminho da AC de teste —
| a verificação continua ligada (VERIFYPEER + VERIFYHOST=2); só a âncora muda. O middleware
| também registra as opções recebidas, para provar que o transporte pediu `verify: true`.
| A verificação de certificado nunca é desligada.
|
| Prova: (1) pino vence mudança de DNS; (2) SNI e verificação usam o NOME; (3) certificado
| inválido para o nome é recusado; (4) nada disso reabre SSRF.
*/

const HTTPS_PIN_HOST = HttpsTestServer::HOST;

/**
 * Middleware de teste: confia na AC de teste mantendo a verificação ligada, e registra as opções.
 */
function trustTestCa(string $caFile): ArrayObject
{
    $seen = new ArrayObject;

    Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $caFile, $seen) {
        $seen->append([
            'verify' => $options['verify'] ?? null,
            'resolve' => $options['curl'][CURLOPT_RESOLVE] ?? null,
            'uri' => (string) $request->getUri(),
        ]);

        if (($options['verify'] ?? null) === true) {
            $options['verify'] = $caFile;
        }

        return $handler($request, $options);
    });

    return $seen;
}

function httpsUrl(int $port, string $path = '/hook'): string
{
    return 'https://'.HTTPS_PIN_HOST.':'.$port.$path;
}

beforeEach(function (): void {
    if (HttpsTestServer::python() === null) {
        $this->markTestSkipped('venv do pdftool ausente (tools/pdftool/.venv): não há como gerar a AC de teste.');
    }

    $context = webhookOrg([HTTPS_PIN_HOST => ['127.0.0.1']], preventStray: false);
    $this->organization = $context['organization'];
    $this->owner = $context['owner'];
    $this->certs = HttpsTestServer::makeCertificates();
    $this->servers = [];
    $this->server = $this->servers[] = HttpsTestServer::start($this->certs, 'good', 'A');
    $this->seen = trustTestCa($this->certs.DIRECTORY_SEPARATOR.'ca.pem');

    config([
        'assinavelox.webhooks.allow_http' => false,
        'assinavelox.webhooks.allowed_ports' => [443, $this->server->port],
        'assinavelox.webhooks.testing_allowed_cidrs' => ['127.0.0.1/32'],
        'assinavelox.webhooks.connect_timeout_seconds' => 3,
        'assinavelox.webhooks.timeout_seconds' => 5,
    ]);
});

afterEach(function (): void {
    foreach ($this->servers ?? [] as $server) {
        $server->stop();
    }

    if (isset($this->certs)) {
        HttpsTestServer::removeCertificates($this->certs);
    }
});

it('entrega HTTPS real pelo IP pinado, com SNI e Host do nome e verificação ligada', function (): void {
    $port = $this->server->port;

    // Controle negativo: sem pino, o nome não resolve no sistema e não chega ao servidor.
    $reachedWithoutPin = true;

    try {
        Http::connectTimeout(2)->timeout(3)->get(httpsUrl($port));
    } catch (ConnectionException) {
        $reachedWithoutPin = false;
    }

    expect($reachedWithoutPin)->toBeFalse()->and($this->server->events())->toBe([]);

    ['secret' => $secret] = makeEndpoint($this->organization, $this->owner, ['*'], httpsUrl($port));
    ['envelope' => $envelope] = webhookEnvelope($this->organization, $this->owner);

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();
    $requests = $this->server->requests();
    $transportCall = collect($this->seen->getArrayCopy())->firstWhere('resolve', '!==', null);

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->history[0]['remote_ip'])->toBe('127.0.0.1')
        ->and($delivery->history[0]['response_excerpt'])->toBe('recebido-A')
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]['sni'])->toBe(HTTPS_PIN_HOST)
        ->and($requests[0]['host'])->toBe(HTTPS_PIN_HOST.':'.$port)
        ->and($requests[0]['path'])->toBe('/hook')
        ->and($requests[0]['body'])->toBe($delivery->payload)
        ->and(WebhookSignature::verify($secret, (string) $requests[0]['signature'], (string) $requests[0]['timestamp'], (string) $requests[0]['body'], Carbon::now()->getTimestamp()))->toBeTrue()
        // O transporte pediu verificação ligada e pinou "nome:porta:127.0.0.1".
        ->and($transportCall['verify'])->toBeTrue()
        ->and($transportCall['resolve'])->toBe([HTTPS_PIN_HOST.':'.$port.':127.0.0.1'])
        ->and($transportCall['uri'])->toBe(httpsUrl($port));
});

it('(1) o DNS muda entre a validação e a conexão: a requisição ainda vai para o IP pinado', function (): void {
    $port = $this->server->port;

    // Um segundo servidor, com certificado VÁLIDO para o nome, no endereço "novo" do DNS.
    try {
        $rebound = $this->servers[] = HttpsTestServer::start($this->certs, 'good', 'B', '127.0.0.2', $port);
    } catch (RuntimeException $exception) {
        $this->markTestSkipped('127.0.0.2 indisponível neste sistema: '.$exception->getMessage());
    }

    config(['assinavelox.webhooks.testing_allowed_cidrs' => ['127.0.0.1/32', '127.0.0.2/32']]);
    $guard = app(OutboundUrlGuard::class);
    $transport = app(WebhookTransport::class);

    $target = $guard->inspect(httpsUrl($port));
    expect($target->pinnedAddress)->toBe('127.0.0.1');

    // Rebinding: depois da validação, o nome passa a apontar para o outro servidor.
    fakeDns([HTTPS_PIN_HOST => ['127.0.0.2']]);

    $pinned = $transport->post($target, '{"ping":true}', []);

    expect($pinned->outcome)->toBe(TransportResult::SUCCEEDED)
        ->and($pinned->remoteIp)->toBe('127.0.0.1')
        ->and($pinned->excerpt)->toBe('recebido-A')
        ->and($this->server->requests())->toHaveCount(1)
        ->and($rebound->events())->toBe([]);

    // Controle: o servidor B é alcançável e aceitaria a conexão — só o pino o manteve de fora.
    $revalidated = $guard->inspect(httpsUrl($port));
    $control = $transport->post($revalidated, '{"ping":true}', []);

    expect($revalidated->pinnedAddress)->toBe('127.0.0.2')
        ->and($control->outcome)->toBe(TransportResult::SUCCEEDED)
        ->and($control->remoteIp)->toBe('127.0.0.2')
        ->and($control->excerpt)->toBe('recebido-B')
        ->and($rebound->requests())->toHaveCount(1)
        ->and($rebound->requests()[0]['sni'])->toBe(HTTPS_PIN_HOST)
        ->and($this->server->requests())->toHaveCount(1);
});

it('(2)(3) o certificado é conferido contra o NOME, não o IP: outro nome, só-IP ou AC estranha são recusados', function (string $certificate): void {
    $server = $this->servers[] = HttpsTestServer::start($this->certs, $certificate, $certificate);
    config(['assinavelox.webhooks.allowed_ports' => [443, $server->port]]);

    $target = app(OutboundUrlGuard::class)->inspect(httpsUrl($server->port));
    $result = app(WebhookTransport::class)->post($target, '{"ping":true}', []);

    $handshakes = $server->events('sni');

    expect($result->succeeded())->toBeFalse()
        ->and($result->outcome)->toBe(TransportResult::FAILED)
        ->and($result->errorCode)->toBe('connection_failed')
        ->and($result->statusCode)->toBeNull()
        // A conexão chegou ao IP pinado (houve ClientHello, com SNI = nome)...
        ->and($handshakes)->not->toBe([])
        ->and(array_unique(array_column($handshakes, 'sni')))->toBe([HTTPS_PIN_HOST])
        // ...mas nenhum byte HTTP (cabeçalhos, corpo, assinatura) foi enviado.
        ->and($server->requests())->toBe([])
        // A verificação estava ligada; ninguém a desligou para "tentar de novo".
        ->and(array_unique(array_column($this->seen->getArrayCopy(), 'verify')))->toBe([true]);
})->with([
    'certificado de outro nome (outro.assinavelox.test)' => 'wrong',
    'certificado só com SAN IP 127.0.0.1 (o IP da conexão)' => 'iponly',
    'certificado do nome certo emitido por AC não confiável' => 'rogue',
]);

it('(3) certificado inválido na entrega real: falha, retentativa agendada, nada recebido', function (): void {
    $server = $this->servers[] = HttpsTestServer::start($this->certs, 'wrong', 'W');
    config(['assinavelox.webhooks.allowed_ports' => [443, $server->port]]);

    makeEndpoint($this->organization, $this->owner, ['*'], httpsUrl($server->port));
    ['envelope' => $envelope] = webhookEnvelope($this->organization, $this->owner);

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->last_error)->toBe('connection_failed')
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($server->requests())->toBe([]);
});

it('(4) nada disso reabre SSRF: loopback sem a exceção de teste, IP literal e rebinding para rede interna', function (): void {
    $port = $this->server->port;
    $guard = app(OutboundUrlGuard::class);

    // Sem a exceção de teste, o mesmo nome (que resolve para 127.0.0.1) é recusado.
    config(['assinavelox.webhooks.testing_allowed_cidrs' => []]);
    expect(fn () => $guard->inspect(httpsUrl($port)))
        ->toThrow(fn (BlockedOutboundUrl $e) => expect($e->reason)->toBe(BlockedOutboundUrl::BLOCKED_ADDRESS));

    // IP literal nunca é aceito, com ou sem a exceção.
    config(['assinavelox.webhooks.testing_allowed_cidrs' => ['127.0.0.1/32']]);
    expect(fn () => $guard->inspect('https://127.0.0.1:'.$port.'/hook'))->toThrow(BlockedOutboundUrl::class);

    // Rebinding para rede interna depois do cadastro: bloqueado antes de qualquer conexão.
    makeEndpoint($this->organization, $this->owner, ['*'], httpsUrl($port));
    fakeDns([HTTPS_PIN_HOST => ['10.0.0.5']]);
    ['envelope' => $envelope] = webhookEnvelope($this->organization, $this->owner);

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->last_error)->toBe('ssrf_blocked_address')
        ->and($delivery->history[0]['outcome'])->toBe(TransportResult::BLOCKED)
        ->and($this->server->events())->toBe([]);
});

it('(4) redirecionamento HTTPS para endereço interno é recusado e NÃO seguido', function (): void {
    makeEndpoint($this->organization, $this->owner, ['*'], httpsUrl($this->server->port, '/redirect'));
    ['envelope' => $envelope] = webhookEnvelope($this->organization, $this->owner);

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->last_error)->toBe('redirect_not_followed')
        ->and($delivery->last_response_code)->toBe(302)
        ->and(array_column($this->server->requests(), 'path'))->toBe(['/redirect']);
});
