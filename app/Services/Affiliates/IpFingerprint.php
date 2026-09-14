<?php

namespace App\Services\Affiliates;

/**
 * HMAC-SHA256 do IP com a APP_KEY: a regra "mesmo IP" compara sem guardar o endereço
 * (minimização, LGPD). Não é reversível sem a chave; muda se a APP_KEY mudar.
 */
final class IpFingerprint
{
    public static function of(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        if ($ip === '') {
            return null;
        }

        return hash_hmac('sha256', 'affiliates-ip|'.self::network($ip), (string) config('app.key'));
    }

    /**
     * O que se compara: IPv4 inteiro; IPv6 pela REDE /64 do assinante (revisão adversarial
     * I-3A). O endereço temporário do IPv6 (RFC 8981) muda sozinho várias vezes por dia dentro do
     * mesmo /64 — comparar o endereço completo deixava a autoindicação passar trocando de endereço.
     */
    public static function network(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return $ip;
        }

        $text = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return $text === false ? $ip : $text.'/64';
    }
}
