<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Http;

use AssinaVelox\Sdk\Exception\NetworkException;
use AssinaVelox\Sdk\Exception\TimeoutException;

/**
 * Transporte com a extensão curl: sem redirecionamento, só http/https, tempo limite total.
 */
final class CurlTransport implements Transport
{
    private const CURLE_OPERATION_TIMEDOUT = 28;

    public function __construct(private readonly float $connectTimeout = 10.0) {}

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): RawResponse
    {
        $responseHeaders = [];
        $handle = curl_init();

        if ($handle === false) {
            throw new NetworkException('Não foi possível iniciar o curl.');
        }

        // "Expect:" vazio: sem 100-continue (atrasaria corpos grandes em alguns servidores).
        $headers[] = 'Expect:';

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min($timeout, $this->connectTimeout) * 1000),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);

                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'http,https');
        } else {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, '');
        }

        $result = curl_exec($handle);

        if ($result === false || ! is_string($result)) {
            $errno = curl_errno($handle);
            $reason = curl_error($handle);

            if ($errno === self::CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutException(sprintf('Tempo esgotado (%s s) em %s %s.', $timeout, $method, self::pathOf($url)));
            }

            throw new NetworkException(sprintf('Falha de conexão em %s %s: %s', $method, self::pathOf($url), $reason));
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        /** @var array<string, string> $responseHeaders */
        return new RawResponse($status, $responseHeaders, $result);
    }

    private static function pathOf(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?? '/');
    }
}
