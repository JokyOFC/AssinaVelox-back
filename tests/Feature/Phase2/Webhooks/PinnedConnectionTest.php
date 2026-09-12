<?php

use App\Enums\AuditEventType;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\TransportResult;
use App\Services\Webhooks\WebhookSignature;
use App\Services\Webhooks\WebhookTransport;
use App\Support\Http\OutboundTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

require_once __DIR__.'/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Risco R6 da viabilidade — o pino de IP funciona no Guzzle instalado?
|--------------------------------------------------------------------------
|
| Teste de INTEGRAÇÃO com conexão real (a única desta pasta), sem sair da máquina: sobe
| `php -S 127.0.0.1:<porta livre>` e entrega para `pinned-receiver.invalid` — um nome do TLD
| reservado `.invalid` (RFC 2606), que NENHUM resolvedor do sistema resolve. O DNS falso da
| aplicação diz 127.0.0.1 (permitido só por `testing_allowed_cidrs`, ignorado em produção).
|
| Se a entrega chega ao servidor, a conexão foi feita no endereço PINADO via CURLOPT_RESOLVE,
| sem consulta ao DNS do sistema — é o que impede o DNS rebinding entre a checagem e a
| conexão. Os controles negativos provam que (a) sem pino o nome não conecta e (b) o endereço
| da conexão é o do pino, não o que o nome "deveria" ter.
|
| Não coberto aqui (documentado em docs/fase-2/webhooks.md §6.4): o caminho HTTPS com SNI e
| certificado — o servidor embutido do PHP não fala TLS. O mecanismo é o mesmo CURLOPT_RESOLVE
| com o nome mantido na URL.
*/

const PIN_HOST = 'pinned-receiver.invalid';

function freeLocalPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($socket === false) {
        throw new RuntimeException("sem porta livre: {$error}");
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) strrchr($name, ':'), 1);
}

/**
 * @return array{process: Process, port: int, log: string}
 */
function startPinServer(): array
{
    $port = freeLocalPort();
    $log = (string) tempnam(sys_get_temp_dir(), 'avpin');

    $process = new Process(
        [PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/Support/pin-server.php'],
        __DIR__.'/Support',
        ['PIN_SERVER_LOG' => $log],
    );
    $process->start();

    $deadline = microtime(true) + 10;

    while (microtime(true) < $deadline) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

        if (is_resource($connection)) {
            fclose($connection);

            return ['process' => $process, 'port' => $port, 'log' => $log];
        }

        if (! $process->isRunning()) {
            break;
        }

        usleep(100_000);
    }

    $process->stop(0);

    throw new RuntimeException('o servidor de teste não subiu: '.$process->getErrorOutput());
}

/**
 * @return list<array<string, mixed>>
 */
function pinServerHits(string $log): array
{
    $content = is_file($log) ? trim((string) file_get_contents($log)) : '';

    return $content === '' ? [] : array_map(
        static fn (string $line): array => (array) json_decode($line, true),
        explode("\n", $content),
    );
}

beforeEach(function (): void {
    $context = webhookOrg([PIN_HOST => ['127.0.0.1']], preventStray: false);
    $this->organization = $context['organization'];
    $this->owner = $context['owner'];
    $this->server = startPinServer();

    config([
        'assinavelox.webhooks.allow_http' => true,
        'assinavelox.webhooks.allowed_ports' => [443, $this->server['port']],
        'assinavelox.webhooks.testing_allowed_cidrs' => ['127.0.0.1/32'],
        'assinavelox.webhooks.connect_timeout_seconds' => 3,
        'assinavelox.webhooks.timeout_seconds' => 5,
    ]);
});

afterEach(function (): void {
    $this->server['process']->stop(1);
    @unlink($this->server['log']);
});

it('R6: o Guzzle instalado honra CURLOPT_RESOLVE — a entrega chega pelo IP pinado sem DNS do sistema', function (): void {
    $port = $this->server['port'];

    // Controle negativo: sem pino, o nome não leva ao servidor.
    $reachedWithoutPin = false;

    try {
        $reachedWithoutPin = Http::connectTimeout(2)->timeout(3)->get('http://'.PIN_HOST.':'.$port.'/hook')->body() === 'recebido';
    } catch (ConnectionException) {
        $reachedWithoutPin = false;
    }

    expect($reachedWithoutPin)->toBeFalse();

    ['secret' => $secret] = makeEndpoint($this->organization, $this->owner, ['*'], 'http://'.PIN_HOST.':'.$port.'/hook');
    ['envelope' => $envelope] = webhookEnvelope($this->organization, $this->owner);

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();
    $hits = pinServerHits($this->server['log']);

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->history[0]['remote_ip'])->toBe('127.0.0.1')
        ->and($delivery->history[0]['response_excerpt'])->toBe('recebido')
        ->and($hits)->toHaveCount(1)
        ->and($hits[0]['host'])->toBe(PIN_HOST.':'.$port)
        ->and($hits[0]['path'])->toBe('/hook')
        ->and($hits[0]['delivery'])->toBe($delivery->ulid)
        ->and($hits[0]['body'])->toBe($delivery->payload)
        ->and(WebhookSignature::verify($secret, (string) $hits[0]['signature'], (string) $hits[0]['timestamp'], (string) $hits[0]['body'], Carbon::now()->getTimestamp()))->toBeTrue();
});

it('o endereço da conexão é o do pino: pinado num endereço sem servidor, a conexão falha', function (): void {
    $port = $this->server['port'];
    $transport = app(WebhookTransport::class);

    $good = $transport->post(
        new OutboundTarget('http://'.PIN_HOST.':'.$port.'/hook', 'http', PIN_HOST, $port, ['127.0.0.1'], '127.0.0.1'),
        '{"ping":true}',
        ['X-Test' => '1'],
    );

    config(['assinavelox.webhooks.connect_timeout_seconds' => 2]);
    $bad = $transport->post(
        new OutboundTarget('http://'.PIN_HOST.':'.$port.'/hook', 'http', PIN_HOST, $port, ['127.0.0.2'], '127.0.0.2'),
        '{"ping":true}',
        ['X-Test' => '1'],
    );

    expect($good->outcome)->toBe(TransportResult::SUCCEEDED)
        ->and($good->remoteIp)->toBe('127.0.0.1')
        ->and($bad->succeeded())->toBeFalse()
        ->and(pinServerHits($this->server['log']))->toHaveCount(1);
});

it('redirecionamento para endereço interno é recusado e NÃO seguido', function (): void {
    $port = $this->server['port'];
    makeEndpoint($this->organization, $this->owner, ['*'], 'http://'.PIN_HOST.':'.$port.'/redirect');
    ['envelope' => $envelope] = webhookEnvelope($this->organization, $this->owner);

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->last_error)->toBe('redirect_not_followed')
        ->and($delivery->last_response_code)->toBe(302)
        ->and(array_column(pinServerHits($this->server['log']), 'path'))->toBe(['/redirect']);
});

it('tempo esgotado real vira resultado desconhecido', function (): void {
    config(['assinavelox.webhooks.timeout_seconds' => 1]);
    $port = $this->server['port'];
    makeEndpoint($this->organization, $this->owner, ['*'], 'http://'.PIN_HOST.':'.$port.'/slow');
    ['envelope' => $envelope] = webhookEnvelope($this->organization, $this->owner);

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->history[0]['outcome'])->toBe(TransportResult::UNKNOWN)
        ->and($delivery->last_error)->toBe('timeout');
});

it('proxy de ambiente é ignorado (ele resolveria o nome por conta própria e anularia o pino)', function (): void {
    $port = $this->server['port'];
    $previous = [getenv('http_proxy'), getenv('HTTPS_PROXY'), getenv('ALL_PROXY')];
    putenv('http_proxy=http://127.0.0.1:9');
    putenv('HTTPS_PROXY=http://127.0.0.1:9');
    putenv('ALL_PROXY=http://127.0.0.1:9');

    try {
        $result = app(WebhookTransport::class)->post(
            new OutboundTarget('http://'.PIN_HOST.':'.$port.'/hook', 'http', PIN_HOST, $port, ['127.0.0.1'], '127.0.0.1'),
            '{"ping":true}',
            [],
        );
    } finally {
        foreach (['http_proxy', 'HTTPS_PROXY', 'ALL_PROXY'] as $index => $name) {
            putenv($previous[$index] === false ? $name : $name.'='.$previous[$index]);
        }
    }

    expect($result->outcome)->toBe(TransportResult::SUCCEEDED)
        ->and(pinServerHits($this->server['log']))->toHaveCount(1);
});
