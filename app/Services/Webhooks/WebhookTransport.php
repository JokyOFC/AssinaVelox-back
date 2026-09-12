<?php

namespace App\Services\Webhooks;

use App\Support\Http\OutboundTarget;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * POST de uma entrega pelo HTTP Client do Laravel (Guzzle 8.2 + cURL), com as travas de rede
 * do roadmap §2.16 (docs/fase-2/webhooks.md §6):
 *
 *  - PINO DE IP: `CURLOPT_RESOLVE` fixa "host:porta" no endereço que o OutboundUrlGuard acabou
 *    de validar. O cURL não consulta o DNS de novo, então um DNS rebinding entre a checagem e a
 *    conexão não tem efeito. O host continua na URL: SNI, cabeçalho Host e validação do
 *    certificado continuam sendo do nome. Coberto por teste de integração com servidor local
 *    (risco R6 da viabilidade: o Guzzle 8.2 aceita `CURLOPT_RESOLVE` na lista de opções).
 *  - `allow_redirects => false`: 3xx é falha; o Location nunca é seguido.
 *  - `protocols => [esquema validado]`: o cURL não fala outro protocolo.
 *  - `proxy => ''`: desliga proxy de ambiente (HTTP(S)_PROXY) — um proxy resolveria o nome por
 *    conta própria e anularia o pino.
 *  - tempos-limite curtos; a resposta vai para um `sink` que guarda só N bytes
 *    (BoundedResponseBuffer) e nunca é interpretada.
 *
 * NUNCA usar `stream => true` aqui: com ele o Guzzle despacha pelo StreamHandler, que não fala
 * cURL — o pino deixaria de existir (no Guzzle 8.2 a requisição falha; no 7 passaria sem pino).
 */
final class WebhookTransport
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $redact  valores que nunca podem aparecer no trecho guardado
     */
    public function post(OutboundTarget $target, string $body, array $headers, array $redact = []): TransportResult
    {
        /** @var TransferStats|null $stats */
        $stats = null;
        $started = hrtime(true);

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'proxy' => '',
                'protocols' => [$target->scheme],
                'curl' => [CURLOPT_RESOLVE => [$target->curlResolveEntry()]],
                'sink' => new BoundedResponseBuffer(max(0, (int) config('assinavelox.webhooks.response_excerpt_bytes', 512))),
                'verify' => true,
                'on_stats' => static function (TransferStats $transfer) use (&$stats): void {
                    $stats = $transfer;
                },
            ])
                ->connectTimeout((float) config('assinavelox.webhooks.connect_timeout_seconds', 5))
                ->timeout((float) config('assinavelox.webhooks.timeout_seconds', 10))
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($target->url);
        } catch (ConnectionException $exception) {
            $timedOut = self::isTimeout($exception);

            return new TransportResult(
                outcome: $timedOut ? TransportResult::UNKNOWN : TransportResult::FAILED,
                durationMs: self::elapsedMs($started),
                errorCode: $timedOut ? 'timeout' : 'connection_failed',
                remoteIp: self::remoteIp($stats),
            );
        }

        $status = $response->status();
        $excerpt = self::excerpt($response->toPsrResponse(), $redact);
        $durationMs = self::elapsedMs($started);
        $remoteIp = self::remoteIp($stats);

        if ($status >= 200 && $status < 300) {
            return new TransportResult(TransportResult::SUCCEEDED, $status, $durationMs, $excerpt, null, $remoteIp);
        }

        return new TransportResult(
            TransportResult::FAILED,
            $status,
            $durationMs,
            $excerpt,
            $status >= 300 && $status < 400 ? 'redirect_not_followed' : 'http_'.$status,
            $remoteIp,
        );
    }

    /**
     * Trecho guardado no histórico: primeiros N bytes, sem caracteres de controle, com o
     * segredo/assinatura desta entrega, qualquer `whsec_…` e números com forma de CPF redigidos.
     *
     * @param  list<string>  $redact
     */
    public static function sanitizeExcerpt(string $text, array $redact = []): ?string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        foreach ($redact as $value) {
            if ($value !== '') {
                $text = str_replace($value, '[redigido]', $text);
            }
        }

        $text = (string) preg_replace('/whsec_[A-Za-z0-9_\-]+/', '[redigido]', $text);
        $text = (string) preg_replace('/(?<!\d)\d{3}\.?\d{3}\.?\d{3}-?\d{2}(?!\d)/', '[redigido]', $text);
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @param  list<string>  $redact
     */
    private static function excerpt(ResponseInterface $response, array $redact): ?string
    {
        $max = max(0, (int) config('assinavelox.webhooks.response_excerpt_bytes', 512));

        if ($max === 0) {
            return null;
        }

        $stream = $response->getBody();
        $chunk = '';

        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            while (! $stream->eof() && strlen($chunk) < $max) {
                $piece = $stream->read($max - strlen($chunk));

                if ($piece === '') {
                    break;
                }

                $chunk .= $piece;
            }
        } catch (Throwable) {
            // Resposta truncada/abortada: guarda o que chegou.
        }

        try {
            $stream->close();
        } catch (Throwable) {
            // nada a fazer
        }

        return self::sanitizeExcerpt($chunk, $redact);
    }

    /**
     * cURL 28 (CURLE_OPERATION_TIMEDOUT) chega na mensagem da ConnectException do Guzzle, que o
     * Laravel repassa na ConnectionException.
     */
    private static function isTimeout(ConnectionException $exception): bool
    {
        $messages = [$exception->getMessage()];
        $previous = $exception->getPrevious();

        if ($previous instanceof ConnectException) {
            $messages[] = $previous->getMessage();
        }

        foreach ($messages as $message) {
            if (preg_match('/cURL error 28\b|timed out|timeout/i', $message) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function remoteIp(?TransferStats $stats): ?string
    {
        $ip = $stats?->getHandlerStat('primary_ip');

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    private static function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
