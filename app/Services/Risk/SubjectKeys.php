<?php

namespace App\Services\Risk;

/**
 * Sujeitos dos sinais sem dado bruto (roadmap §3.7 "minimização").
 *
 * - {@see self::digest()}: HMAC-SHA256 com chave própria (`assinavelox.risk.subject_key`) ou,
 *   na falta dela, derivada da APP_KEY. Permite contar "o mesmo link", "o mesmo prefixo de
 *   rede" ou "o mesmo dispositivo declarado" sem guardar o valor.
 * - {@see self::truncateIp()}: IPv4 vira /24 (`203.0.113.0/24`), IPv6 vira /48. É a única
 *   forma de IP que entra em evidência.
 */
final class SubjectKeys
{
    public static function digest(string $raw): string
    {
        return hash_hmac('sha256', $raw, self::key());
    }

    public static function truncateIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $ip);

            return $octets[0].'.'.$octets[1].'.'.$octets[2].'.0/24';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);

            if ($packed === false) {
                return null;
            }

            $prefix = substr($packed, 0, 6).str_repeat("\0", 10);
            $text = inet_ntop($prefix);

            return $text === false ? null : $text.'/48';
        }

        return null;
    }

    private static function key(): string
    {
        $configured = config('assinavelox.risk.subject_key');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return hash_hmac('sha256', 'assinavelox-risk-subject-key', (string) config('app.key'));
    }
}
