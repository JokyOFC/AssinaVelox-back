<?php

namespace App\Integrations\Identity\Verifiky;

/**
 * Assinatura HMAC das LEITURAS na Verifiky (`GET /api/verifiky/verificacoes/{id}` e arquivos),
 * como no VerifikyReadClient do metta-bank. O segredo (`VERIFIKY_HMAC_SECRET`) é SEPARADO do
 * segredo do webhook, por desenho da Verifiky.
 *
 * Texto canônico, uma parte por linha: método, caminho, query ordenada (RFC 3986), timestamp,
 * nonce e SHA-256 do corpo. Cabeçalhos: `X-Verifiky-Timestamp`, `X-Verifiky-Nonce`,
 * `X-Verifiky-Signature` (HMAC-SHA256 em hexadecimal). O `Authorization: Bearer` vai à parte.
 */
final class VerifikyRequestSigner
{
    public function isConfigured(): bool
    {
        return $this->secret() !== '';
    }

    /**
     * @return array<string, string>
     */
    public function headers(string $method, string $url, string $body = '', ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(12));

        return [
            'X-Verifiky-Timestamp' => $timestamp,
            'X-Verifiky-Nonce' => $nonce,
            'X-Verifiky-Signature' => hash_hmac('sha256', self::canonical($method, $url, $timestamp, $nonce, $body), $this->secret()),
        ];
    }

    public static function canonical(string $method, string $url, string $timestamp, string $nonce, string $body = ''): string
    {
        $parts = parse_url($url) ?: [];

        parse_str((string) ($parts['query'] ?? ''), $query);
        ksort($query);

        return implode("\n", [
            strtoupper($method),
            (string) ($parts['path'] ?? '/'),
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    private function secret(): string
    {
        return trim((string) config('assinavelox.identity_verification.verifiky.hmac_secret', ''));
    }
}
