<?php

namespace App\Services\Webhooks;

/**
 * Assinatura das entregas (docs/fase-2/webhooks.md §4).
 *
 *     X-AssinaVelox-Timestamp: 1789142400
 *     X-AssinaVelox-Signature: v1=<hex HMAC-SHA256(segredo, "{timestamp}.{corpo bruto}")>
 *
 * Durante a rotação do segredo (janela de convivência) o cabeçalho traz DUAS assinaturas,
 * separadas por vírgula — uma com o segredo novo e outra com o anterior —, e o receptor
 * aceita se QUALQUER uma conferir com o segredo que ele tem. Fora da janela, só a do novo.
 *
 * A chave do HMAC é o segredo inteiro, como mostrado (incluindo o prefixo `whsec_`).
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

    /** Janela de tolerância recomendada ao receptor (segundos). */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    public static function generateSecret(): string
    {
        return self::SECRET_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Pista para a tela ("…abcd"): os 4 últimos caracteres, nunca mais que isso. */
    public static function hint(string $secret): string
    {
        return '…'.substr($secret, -4);
    }

    public static function compute(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * @param  list<string>  $secrets  segredo vigente primeiro; o anterior só dentro da janela
     */
    public static function header(array $secrets, int $timestamp, string $rawBody): string
    {
        return implode(', ', array_map(
            static fn (string $secret): string => self::VERSION.'='.self::compute($secret, $timestamp, $rawBody),
            $secrets,
        ));
    }

    /**
     * Implementação de referência do RECEPTOR (a mesma lógica dos exemplos da documentação):
     * confere a janela de tempo e compara em tempo constante com cada assinatura `v1`.
     */
    public static function verify(
        string $secret,
        string $signatureHeader,
        string $timestampHeader,
        string $rawBody,
        int $now,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): bool {
        if (preg_match('/^\d{1,12}$/', $timestampHeader) !== 1) {
            return false;
        }

        $timestamp = (int) $timestampHeader;

        if (abs($now - $timestamp) > $toleranceSeconds) {
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
}
