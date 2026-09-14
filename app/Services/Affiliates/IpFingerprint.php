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

        return hash_hmac('sha256', 'affiliates-ip|'.$ip, (string) config('app.key'));
    }
}
