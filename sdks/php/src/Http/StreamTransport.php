<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Http;

use AssinaVelox\Sdk\Exception\NetworkException;
use AssinaVelox\Sdk\Exception\TimeoutException;

/**
 * Transporte com os streams do PHP (sem extensão nenhuma): sem redirecionamento, TLS com
 * verificação de certificado e de nome.
 */
final class StreamTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): RawResponse
    {
        $headers[] = 'Connection: close';

        if ($body !== null) {
            $headers[] = 'Content-Length: '.strlen($body);
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $headers[] = 'Content-Length: 0';
        }

        $http = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
        ];

        if ($body !== null) {
            $http['content'] = $body;
        }

        $context = stream_context_create([
            'http' => $http,
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $warning = null;
        $started = microtime(true);
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $stream = fopen($url, 'rb', false, $context);
            $content = $stream === false ? false : stream_get_contents($stream);
            $meta = $stream === false ? [] : stream_get_meta_data($stream);

            if ($stream !== false) {
                fclose($stream);
            }
        } finally {
            restore_error_handler();
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        $elapsed = microtime(true) - $started;
        $timedOut = ($meta['timed_out'] ?? false) === true
            || str_contains(strtolower((string) $warning), 'timed out')
            || ($content === false && $elapsed >= $timeout * 0.9);

        if ($timedOut) {
            throw new TimeoutException(sprintf('Tempo esgotado (%s s) em %s %s.', $timeout, $method, $path));
        }

        if ($stream === false || $content === false) {
            $reason = preg_replace('/^.*?:\s*/', '', (string) $warning) ?: 'sem resposta';

            throw new NetworkException(sprintf('Falha de conexão em %s %s: %s', $method, $path, $reason));
        }

        $status = 0;
        $responseHeaders = [];

        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            $line = (string) $line;

            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        if ($status === 0) {
            throw new NetworkException(sprintf('Resposta HTTP sem linha de status em %s %s.', $method, $path));
        }

        return new RawResponse($status, $responseHeaders, $content);
    }
}
