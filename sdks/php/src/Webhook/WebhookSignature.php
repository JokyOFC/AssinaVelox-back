<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Webhook;

use AssinaVelox\Sdk\Exception\WebhookSignatureException;

/**
 * Verificação da assinatura dos webhooks de saída da AssinaVelox — o MESMO algoritmo de
 * App\Services\Webhooks\WebhookSignature::verify() (docs/fase-2/webhooks.md §4), conferido
 * pelos vetores gerados pelo PHP real (sdks/testdata/webhook-signature-vectors.json).
 *
 *     X-AssinaVelox-Signature: v1=<hex(HMAC-SHA256(segredo, "{timestamp}.{corpo bruto}"))>
 *
 * - a chave do HMAC é o segredo inteiro, como mostrado (inclui `whsec_`);
 * - confira sobre os BYTES BRUTOS do corpo (file_get_contents('php://input')), antes do JSON;
 * - janela de tempo: recuse se |agora − timestamp| > 300 s (replay);
 * - na rotação do segredo o cabeçalho traz duas assinaturas; basta uma conferir;
 * - comparação em tempo constante (hash_equals).
 */
final class WebhookSignature
{
    public const VERSION = 'v1';

    public const SECRET_PREFIX = 'whsec_';

    public const HEADER_SIGNATURE = 'X-AssinaVelox-Signature';

    public const HEADER_TIMESTAMP = 'X-AssinaVelox-Timestamp';

    public const HEADER_DELIVERY = 'X-AssinaVelox-Delivery-Id';

    public const HEADER_EVENT = 'X-AssinaVelox-Event';

    public const HEADER_EVENT_ID = 'X-AssinaVelox-Event-Id';

    public const HEADER_ATTEMPT = 'X-AssinaVelox-Attempt';

    public const DEFAULT_TOLERANCE_SECONDS = 300;

    public static function compute(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * Valor do cabeçalho como a plataforma envia (útil nos testes do seu receptor).
     *
     * @param  list<string>  $secrets  segredo vigente primeiro
     */
    public static function header(array $secrets, int $timestamp, string $rawBody): string
    {
        return implode(', ', array_map(
            static fn (string $secret): string => self::VERSION.'='.self::compute($secret, $timestamp, $rawBody),
            $secrets,
        ));
    }

    public static function verify(
        string $secret,
        string $signatureHeader,
        string $timestampHeader,
        string $rawBody,
        ?int $now = null,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): bool {
        if (preg_match('/^\d{1,12}$/', $timestampHeader) !== 1) {
            return false;
        }

        $timestamp = (int) $timestampHeader;

        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = self::compute($secret, $timestamp, $rawBody);

        foreach (explode(',', $signatureHeader) as $part) {
            [$version, $signature] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($version === self::VERSION && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Confere a assinatura e devolve o evento decodificado; senão, WebhookSignatureException.
     * Deduplique pelo cabeçalho X-AssinaVelox-Delivery-Id (igual em todas as tentativas).
     *
     * @param  array<string, string|list<string>>  $headers  nomes em qualquer caixa (ex.: getallheaders() ou PSR-7)
     * @return array<string, mixed>
     */
    public static function constructEvent(
        string $rawBody,
        array $headers,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): array {
        $signature = self::headerValue($headers, self::HEADER_SIGNATURE);
        $timestamp = self::headerValue($headers, self::HEADER_TIMESTAMP);

        if ($signature === null || $timestamp === null || ! self::verify($secret, $signature, $timestamp, $rawBody, $now, $toleranceSeconds)) {
            throw new WebhookSignatureException('Assinatura do webhook inválida ou fora da janela de tempo.');
        }

        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new WebhookSignatureException('O corpo do webhook não é JSON válido.', 0, $exception);
        }

        if (! is_array($event)) {
            throw new WebhookSignatureException('O corpo do webhook não é um objeto JSON.');
        }

        return $event;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                $value = is_array($value) ? ($value[0] ?? null) : $value;

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }
}
