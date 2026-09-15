<?php

use App\Integrations\Dropbox\DropboxSource;
use App\Services\CloudImport\CloudImportRejected;
use App\Services\CloudImport\CloudProvider;
use App\Services\CloudImport\Dto\CloudFileSelection;
use App\Support\Http\DnsResolver;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;
use Tests\Feature\Phase2\Webhooks\Support\FakeDnsResolver;
use Tests\Support\Https\HttpsTestServer;

/*
|--------------------------------------------------------------------------
| Revisão adversarial onda G (conectores) — teto do download furado por Content-Encoding
|--------------------------------------------------------------------------
| ConnectorHttp::download() promete "download com teto de bytes: transferência abortada pelo
| `progress` ao passar do teto" (docs/fase-3/conectores.md §6). Mas o pedido sai com o
| `decode_content` padrão do Guzzle (true): o Guzzle apaga o `Accept-Encoding` do pedido, mas
| deixa CURLOPT_ENCODING = '' — o cURL DESCOMPRIME qualquer Content-Encoding que a resposta
| trouxer, mesmo sem ter pedido. O `progress` do cURL conta os bytes que vieram pela rede
| (comprimidos) e o `Content-Length` conferido em `on_headers` é o comprimido; já o `sink` em
| arquivo recebe o corpo DESCOMPRIMIDO, sem teto. Medido: 48.942 bytes na rede viraram
| 50.331.648 bytes gravados no disco temporário ANTES do `filesize() > $maxBytes` final recusar
| o arquivo. Uma resposta com Content-Encoding não solicitado vinda de um host da lista
| (dl.dropboxusercontent.com, *.dropboxusercontent.com, www.googleapis.com — ou um host a mais
| em ASSINAVELOX_DROPBOX_DOWNLOAD_HOSTS) enche o disco até o tempo-limite de 60 s. Depende de o
| provedor mandar compressão não pedida (não é o comportamento documentado do Google nem do
| Dropbox): é uma garantia documentada que não se sustenta, não um ataque direto.
|
| Prova com TLS real e sem sair da máquina (mesma infraestrutura do HttpsPinTest): AC de teste,
| o nome `hooks.assinavelox.test` na lista de hosts do Dropbox e o DNS falso apontando para
| 127.0.0.1. Um middleware SÓ DE TESTE troca `verify: true` pela AC de teste e embrulha o `sink`
| para CONTAR os bytes gravados (o arquivo continua o mesmo). O controle (/chunked, sem
| compressão) mostra que o teto funciona quando não há Content-Encoding.
|
| Correção sugerida: `decode_content => false` com `Accept-Encoding: identity` no download, ou um
| sink limitado (como o BoundedResponseBuffer do SsoHttpClient) que conte os bytes DECODIFICADOS.
*/

const REVIEW_3G_CAP_BYTES = 1024 * 1024;
const REVIEW_3G_SLACK_BYTES = 512 * 1024;

function review3gStartEncodedServer(string $certificates): array
{
    $port = HttpsTestServer::freePort();
    $log = (string) tempnam(sys_get_temp_dir(), 'av3glog');
    $process = new Process([
        (string) HttpsTestServer::python(), __DIR__.'/Support/encoded_body_server.py', '127.0.0.1', (string) $port,
        $certificates.DIRECTORY_SEPARATOR.'good.pem', $certificates.DIRECTORY_SEPARATOR.'good.key', $log,
    ]);
    $process->setTimeout(null);
    $process->start();

    $deadline = microtime(true) + 30;

    while (microtime(true) < $deadline && ! str_contains($process->getOutput(), 'ready')) {
        if (! $process->isRunning()) {
            break;
        }

        usleep(50_000);
    }

    if (! str_contains($process->getOutput(), 'ready')) {
        $process->stop(0);

        throw new RuntimeException('servidor de teste não subiu: '.$process->getErrorOutput());
    }

    return [$process, $port, $log];
}

/**
 * Confia na AC de teste (verificação continua ligada) e conta os bytes gravados no sink.
 */
function review3gProbe(string $caFile): ArrayObject
{
    $probe = new ArrayObject(['written' => 0, 'progress' => 0, 'paths' => [], 'streams' => []]);

    Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $caFile, $probe) {
        if (($options['verify'] ?? null) === true) {
            $options['verify'] = $caFile;
        }

        if (is_string($options['sink'] ?? null)) {
            $path = $options['sink'];
            $inner = Utils::streamFor(fopen($path, 'w+b'));
            $probe['paths'] = [...$probe['paths'], $path];
            $probe['streams'] = [...$probe['streams'], $inner];
            $options['sink'] = FnStream::decorate($inner, [
                'write' => static function (string $bytes) use ($inner, $probe): int {
                    $probe['written'] = $probe['written'] + strlen($bytes);

                    return $inner->write($bytes);
                },
            ]);
        } elseif (is_resource($options['sink'] ?? null)) {
            // Sink em recurso (o arquivo com o filtro de escrita da correção): conta o que é
            // OFERECIDO a ele — a mesma medida do caso em caminho, sem afrouxar a asserção.
            $inner = Utils::streamFor($options['sink']);
            $probe['streams'] = [...$probe['streams'], $inner];
            $options['sink'] = FnStream::decorate($inner, [
                'write' => static function (string $bytes) use ($inner, $probe): int {
                    $probe['written'] = $probe['written'] + strlen($bytes);

                    return $inner->write($bytes);
                },
            ]);
        }

        if (isset($options['progress']) && is_callable($options['progress'])) {
            $original = $options['progress'];
            $options['progress'] = static function (...$arguments) use ($original, $probe) {
                $probe['progress'] = max($probe['progress'], (int) ($arguments[1] ?? 0));

                return $original(...$arguments);
            };
        }

        return $handler($request, $options);
    });

    return $probe;
}

function review3gFetch(int $port, string $path): ?CloudImportRejected
{
    try {
        app(DropboxSource::class)->fetch(new CloudFileSelection(
            CloudProvider::Dropbox,
            name: 'contrato.pdf',
            link: 'https://'.HttpsTestServer::HOST.':'.$port.$path,
        ), REVIEW_3G_CAP_BYTES);
    } catch (CloudImportRejected $rejected) {
        return $rejected;
    }

    return null;
}

beforeEach(function (): void {
    if (HttpsTestServer::python() === null) {
        $this->markTestSkipped('venv do pdftool ausente (tools/pdftool/.venv): não há como gerar a AC de teste.');
    }

    $this->certs = HttpsTestServer::makeCertificates();
    [$this->server, $this->port, $this->log] = review3gStartEncodedServer($this->certs);
    $this->probe = review3gProbe($this->certs.DIRECTORY_SEPARATOR.'ca.pem');

    app()->instance(DnsResolver::class, (new FakeDnsResolver)->set(HttpsTestServer::HOST, ['127.0.0.1']));

    config([
        'services.dropbox.app_key' => 'dropbox-app-key',
        'assinavelox.cloud_import.dropbox_download_hosts' => [HttpsTestServer::HOST],
        'assinavelox.cloud_import.connect_timeout_seconds' => 3,
        'assinavelox.cloud_import.timeout_seconds' => 30,
        'assinavelox.webhooks.allow_http' => false,
        'assinavelox.webhooks.allowed_ports' => [443, $this->port],
        'assinavelox.webhooks.testing_allowed_cidrs' => ['127.0.0.1/32'],
    ]);
});

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop(1);
    }

    if (isset($this->probe)) {
        $paths = $this->probe['paths'];

        foreach ($this->probe['streams'] as $stream) {
            $stream->close();
        }

        $this->probe['streams'] = [];

        foreach ($paths as $path) {
            @unlink($path);
        }
    }

    if (isset($this->log)) {
        @unlink($this->log);
    }

    if (isset($this->certs)) {
        HttpsTestServer::removeCertificates($this->certs);
    }
});

it('corpo com Content-Encoding gzip: o teto vale para os bytes GRAVADOS, não só para os que vieram pela rede', function (): void {
    $rejected = review3gFetch($this->port, '/bomb');

    $requests = array_map(static fn (string $line): array => (array) json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($this->log))));

    // O cliente NÃO anuncia gzip (o Guzzle apaga o cabeçalho), mas deixa o cURL decodificar o
    // que vier: a compressão aqui é não solicitada.
    expect($requests)->toHaveCount(1);

    // No fim o arquivo é recusado (o filesize() depois do download pega)...
    expect($rejected)->not->toBeNull()
        ->and($rejected->errorCode)->toBe('file_too_large');

    // ...mas o `progress` só viu os bytes comprimidos e nunca abortou a transferência,
    expect($this->probe['progress'])->toBeLessThan(REVIEW_3G_CAP_BYTES);

    // e o disco recebeu o corpo inteiro descomprimido (48 MB para um teto de 1 MB).
    expect($this->probe['written'])->toBeLessThanOrEqual(
        REVIEW_3G_CAP_BYTES + REVIEW_3G_SLACK_BYTES,
        sprintf('%d bytes gravados no temporário para um teto de %d bytes (%d bytes vieram pela rede; Accept-Encoding enviado: "%s").', $this->probe['written'], REVIEW_3G_CAP_BYTES, (int) $requests[0]['compressed_bytes'], (string) $requests[0]['accept_encoding']),
    );
});

it('controle: sem Content-Encoding o progress aborta a transferência perto do teto', function (): void {
    $rejected = review3gFetch($this->port, '/chunked');

    expect($rejected)->not->toBeNull()
        ->and($rejected->errorCode)->toBe('file_too_large')
        ->and($this->probe['written'])->toBeLessThanOrEqual(REVIEW_3G_CAP_BYTES + REVIEW_3G_SLACK_BYTES);
});
