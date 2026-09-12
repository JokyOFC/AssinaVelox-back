<?php

namespace App\Services\Api;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * Limites da API v1 (limitador nomeado `api`, registrado em AppServiceProvider):
 * um balde por TOKEN e outro pela ORGANIZAÇÃO do token (vários tokens da mesma organização
 * dividem o teto dela). Sem token (não deveria acontecer: o limitador roda depois da
 * autenticação) o balde é por IP.
 */
final class ApiRateLimits
{
    /**
     * @return list<Limit>
     */
    public static function for(Request $request): array
    {
        $token = ApiContext::token($request);

        if ($token === null) {
            return [Limit::perMinute(60)->by('anon|'.$request->ip())];
        }

        return [
            Limit::perMinute(self::perToken())->by('token|'.$token->getKey()),
            Limit::perMinute(self::perOrganization())->by('org|'.$token->organization_id),
        ];
    }

    public static function perToken(): int
    {
        return max(1, (int) config('assinavelox.api.rate_limit.per_token_per_minute', 120));
    }

    public static function perOrganization(): int
    {
        return max(1, (int) config('assinavelox.api.rate_limit.per_organization_per_minute', 600));
    }

    public static function failedAuthPerMinute(): int
    {
        return max(1, (int) config('assinavelox.api.rate_limit.failed_auth_per_minute', 30));
    }
}
