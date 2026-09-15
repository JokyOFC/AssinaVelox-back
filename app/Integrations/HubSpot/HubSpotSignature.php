<?php

namespace App\Integrations\HubSpot;

/**
 * Assinatura v3 das requisições do HubSpot (ação de workflow — docs/fase-3/conectores.md §5.2).
 *
 * Como a documentação do HubSpot descreve a validação v3:
 *
 *  1. `X-HubSpot-Request-Timestamp` (milissegundos) precisa estar dentro da janela
 *     (`hubspot.signature_tolerance_seconds`, 5 minutos por padrão) — fora dela, recusa;
 *  2. mensagem = MÉTODO + URI + corpo bruto + timestamp, onde a URI é a URL completa chamada
 *     (com query) e os caracteres codificados `%3A %2F %3F %40 %21 %24 %27 %28 %29 %2A %2C %3B`
 *     voltam para `: / ? @ ! $ ' ( ) * , ;`;
 *  3. HMAC-SHA256 com o client secret do app, em base64;
 *  4. comparação em tempo constante com `X-HubSpot-Signature-v3`.
 *
 * O segredo nunca sai daqui. O motivo da recusa é um código para log interno; a resposta ao
 * cliente é sempre a mesma ("assinatura inválida"), para não virar oráculo.
 */
final class HubSpotSignature
{
    public const MISSING_HEADERS = 'missing_headers';

    public const STALE_TIMESTAMP = 'stale_timestamp';

    public const INVALID_SIGNATURE = 'invalid_signature';

    public const NOT_CONFIGURED = 'not_configured';

    private const DECODE = [
        '%3A' => ':', '%3a' => ':',
        '%2F' => '/', '%2f' => '/',
        '%3F' => '?', '%3f' => '?',
        '%40' => '@',
        '%21' => '!',
        '%24' => '$',
        '%27' => "'",
        '%28' => '(',
        '%29' => ')',
        '%2A' => '*', '%2a' => '*',
        '%2C' => ',', '%2c' => ',',
        '%3B' => ';', '%3b' => ';',
    ];

    /**
     * null = válida; senão, o código do motivo.
     */
    public function verify(
        string $method,
        string $uri,
        string $body,
        ?string $signature,
        ?string $timestamp,
        #[\SensitiveParameter] string $secret,
        int $toleranceSeconds,
        ?int $nowMs = null,
    ): ?string {
        if ($secret === '') {
            return self::NOT_CONFIGURED;
        }

        $signature = is_string($signature) ? trim($signature) : '';
        $timestamp = is_string($timestamp) ? trim($timestamp) : '';

        if ($signature === '' || $timestamp === '' || ! ctype_digit($timestamp) || strlen($timestamp) > 16) {
            return self::MISSING_HEADERS;
        }

        $now = $nowMs ?? (int) floor(microtime(true) * 1000);

        if (abs($now - (int) $timestamp) > max(1, $toleranceSeconds) * 1000) {
            return self::STALE_TIMESTAMP;
        }

        return hash_equals(self::sign($method, $uri, $body, $timestamp, $secret), $signature)
            ? null
            : self::INVALID_SIGNATURE;
    }

    public static function sign(string $method, string $uri, string $body, string $timestamp, #[\SensitiveParameter] string $secret): string
    {
        return base64_encode(hash_hmac('sha256', strtoupper($method).self::normalizeUri($uri).$body.$timestamp, $secret, true));
    }

    public static function normalizeUri(string $uri): string
    {
        return strtr($uri, self::DECODE);
    }
}
