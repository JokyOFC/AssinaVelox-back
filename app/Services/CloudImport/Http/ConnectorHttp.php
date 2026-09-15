<?php

namespace App\Services\CloudImport\Http;

use App\Support\Http\BlockedOutboundUrl;
use App\Support\Http\OutboundTarget;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Cliente HTTP dos conectores (Google Drive, Dropbox, HubSpot — Fase 3 §3.9, G-CONN,
 * docs/fase-3/conectores.md §6). É a MESMA proteção contra SSRF dos webhooks de saída
 * (docs/fase-2/webhooks.md §6), mais uma lista de hosts permitidos por provedor:
 *
 *  1. só `https` (nem fora de produção existe `http` aqui: todo provedor é público);
 *  2. o host precisa estar na lista do provedor ANTES de qualquer DNS (`host` exato ou
 *     `.sufixo` = o domínio e seus subdomínios) — um link do Dropbox que aponte para outro
 *     lugar morre aqui, sem consulta nenhuma;
 *  3. App\Support\Http\OutboundUrlGuard: IP literal em qualquer grafia, sufixos internos,
 *     porta, e TODOS os endereços resolvidos precisam ser públicos;
 *  4. conexão PINADA no endereço validado (CURLOPT_RESOLVE), sem redirecionamento, sem proxy
 *     de ambiente, só o protocolo validado — as mesmas opções de WebhookTransport;
 *  5. download com teto de bytes: `Content-Length` conferido nos cabeçalhos, transferência
 *     abortada pelo `progress` ao passar do teto, e o tamanho gravado conferido de novo.
 *
 * Nenhuma mensagem de erro carrega URL, cabeçalho, corpo ou token: só ConnectorFailure com
 * um código. Nada é logado aqui.
 */
final class ConnectorHttp
{
    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * @param  list<string>  $allowedHosts
     *
     * @throws ConnectorFailure
     */
    public function target(string $url, array $allowedHosts): OutboundTarget
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
            throw new ConnectorFailure(ConnectorFailure::BLOCKED_URL, null, BlockedOutboundUrl::SCHEME_NOT_ALLOWED);
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if (! self::hostAllowed($host, $allowedHosts)) {
            throw new ConnectorFailure(ConnectorFailure::BLOCKED_URL, null, BlockedOutboundUrl::HOST_NOT_ALLOWED);
        }

        try {
            return $this->guard->inspect($url);
        } catch (BlockedOutboundUrl $exception) {
            throw new ConnectorFailure(ConnectorFailure::BLOCKED_URL, null, $exception->reason);
        }
    }

    /**
     * `host` casa exatamente; `.dominio.com` casa `dominio.com` e qualquer subdomínio dele.
     *
     * @param  list<string>  $allowedHosts
     */
    public static function hostAllowed(string $host, array $allowedHosts): bool
    {
        $host = strtolower(rtrim($host, '.'));

        if ($host === '') {
            return false;
        }

        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === '') {
                continue;
            }

            if (str_starts_with($allowed, '.')) {
                $base = substr($allowed, 1);

                if ($base !== '' && ($host === $base || str_ends_with($host, $allowed))) {
                    return true;
                }

                continue;
            }

            if ($host === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Requisição com as travas de rede aplicadas ao destino já validado.
     */
    public function request(OutboundTarget $target): PendingRequest
    {
        return Http::withOptions([
            'allow_redirects' => false,
            'proxy' => '',
            'protocols' => ['https'],
            'curl' => [CURLOPT_RESOLVE => [$target->curlResolveEntry()]],
            'verify' => true,
        ])
            ->connectTimeout((float) config('assinavelox.cloud_import.connect_timeout_seconds', 5))
            ->timeout((float) config('assinavelox.cloud_import.timeout_seconds', 60))
            ->withUserAgent('AssinaVelox-Connectors/1.0');
    }

    /**
     * POST `application/x-www-form-urlencoded` (endpoints de token OAuth).
     *
     * @param  list<string>  $allowedHosts
     * @param  array<string, scalar>  $fields
     * @param  array<string, string>  $headers
     *
     * @throws ConnectorFailure
     */
    public function postForm(string $url, array $allowedHosts, #[\SensitiveParameter] array $fields, #[\SensitiveParameter] array $headers = []): Response
    {
        $target = $this->target($url, $allowedHosts);

        return $this->run(fn (): Response => $this->request($target)->withHeaders($headers)->acceptJson()->asForm()->post($target->url, $fields));
    }

    /**
     * @param  list<string>  $allowedHosts
     * @param  array<string, string>  $headers
     *
     * @throws ConnectorFailure
     */
    public function getJson(string $url, array $allowedHosts, #[\SensitiveParameter] array $headers = []): Response
    {
        $target = $this->target($url, $allowedHosts);

        return $this->run(fn (): Response => $this->request($target)->withHeaders($headers)->acceptJson()->get($target->url));
    }

    /**
     * @param  list<string>  $allowedHosts
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     *
     * @throws ConnectorFailure
     */
    public function sendJson(string $method, string $url, array $allowedHosts, array $body, #[\SensitiveParameter] array $headers = []): Response
    {
        $target = $this->target($url, $allowedHosts);

        return $this->run(fn (): Response => $this->request($target)->withHeaders($headers)->acceptJson()->asJson()->send($method, $target->url, ['json' => $body]));
    }

    /**
     * GET que grava o corpo num temporário, com teto de bytes durante a transferência.
     *
     * NUNCA `stream => true` (o Guzzle trocaria o handler cURL pelo StreamHandler e o pino de IP
     * deixaria de existir — achado do risco R6, WebhookTransport): o corpo vai para um `sink`
     * em arquivo e o teto é imposto por `on_headers` + `progress`.
     *
     * @param  list<string>  $allowedHosts
     * @param  array<string, string>  $headers
     *
     * @throws ConnectorFailure
     */
    public function download(string $url, array $allowedHosts, #[\SensitiveParameter] array $headers, int $maxBytes): DownloadedFile
    {
        $target = $this->target($url, $allowedHosts);
        $path = self::temporaryPath();
        // Marcado pelos callbacks do Guzzle (cabeçalho ou progresso) e pelo filtro de escrita do
        // temporário quando o teto é passado.
        $limit = new DownloadLimit($maxBytes);
        // O teto vale para os bytes GRAVADOS (depois de uma descompressão não pedida), não só
        // para os que vieram pela rede: o sink é o arquivo com BoundedWriteFilter.
        $sink = self::boundedSink($path, $limit);

        try {
            $response = $this->run(fn (): Response => $this->request($target)
                ->withHeaders($headers)
                ->withOptions([
                    'sink' => $sink,
                    'on_headers' => static function (ResponseInterface $response) use ($limit): void {
                        $length = trim($response->getHeaderLine('Content-Length'));

                        if ($length !== '' && ctype_digit($length) && $limit->exceededBy((int) $length)) {
                            throw new RuntimeException('too_large');
                        }
                    },
                    'progress' => static fn (int $downloadTotal, int $downloaded): bool => $limit->exceededBy(max($downloadTotal, $downloaded)),
                ])
                ->get($target->url));
        } catch (ConnectorFailure $failure) {
            self::close($sink);
            self::discard($path);

            throw $limit->exceeded() ? new ConnectorFailure(ConnectorFailure::TOO_LARGE) : $failure;
        }

        // Fechado antes de medir e de devolver (no Windows, arquivo aberto não é apagado).
        self::close($sink);

        if ($limit->exceeded()) {
            self::discard($path);

            throw new ConnectorFailure(ConnectorFailure::TOO_LARGE);
        }

        if (! $response->successful()) {
            self::discard($path);

            throw ConnectorFailure::http($response->status());
        }

        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;

        if ($size > $maxBytes) {
            self::discard($path);

            throw new ConnectorFailure(ConnectorFailure::TOO_LARGE);
        }

        if ($size <= 0) {
            self::discard($path);

            throw new ConnectorFailure(ConnectorFailure::EMPTY);
        }

        $type = trim($response->header('Content-Type'));

        return new DownloadedFile($path, $size, $type === '' ? null : $type);
    }

    public static function discard(?string $path): void
    {
        if ($path !== null && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Temporário aberto para escrita com BoundedWriteFilter: o teto vale para os bytes que
     * chegam ao disco (já descomprimidos), e a escrita falha — abortando o cURL — ao passar dele.
     *
     * @return resource
     *
     * @throws ConnectorFailure
     */
    private static function boundedSink(string $path, DownloadLimit $limit)
    {
        BoundedWriteFilter::register();
        $handle = @fopen($path, 'w+b');

        if ($handle === false) {
            self::discard($path);

            throw new ConnectorFailure(ConnectorFailure::CONNECTION_FAILED);
        }

        if (stream_filter_append($handle, BoundedWriteFilter::NAME, STREAM_FILTER_WRITE, $limit) === false) {
            fclose($handle);
            self::discard($path);

            throw new ConnectorFailure(ConnectorFailure::CONNECTION_FAILED);
        }

        return $handle;
    }

    /**
     * @param  resource|null  $handle
     */
    private static function close($handle): void
    {
        if (is_resource($handle)) {
            @fclose($handle);
        }
    }

    private static function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'av-cloud-');

        if ($path === false) {
            throw new ConnectorFailure(ConnectorFailure::CONNECTION_FAILED);
        }

        return $path;
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws ConnectorFailure
     */
    private function run(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectorFailure $failure) {
            throw $failure;
        } catch (ConnectionException $exception) {
            throw new ConnectorFailure(self::isTimeout($exception) ? ConnectorFailure::TIMEOUT : ConnectorFailure::CONNECTION_FAILED);
        } catch (Throwable) {
            // Qualquer outra falha de transporte (TLS, abortada pelo teto, resposta inválida):
            // o detalhe pode conter a URL; só o código sai daqui.
            throw new ConnectorFailure(ConnectorFailure::CONNECTION_FAILED);
        }
    }

    private static function isTimeout(ConnectionException $exception): bool
    {
        return preg_match('/cURL error 28\b|timed out|timeout/i', $exception->getMessage()) === 1;
    }
}
