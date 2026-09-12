<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite da API v1 pelo limitador nomeado `api` (por token E por organização), com os
 * cabeçalhos do rascunho IETF "RateLimit header fields for HTTP":
 *
 *  - `RateLimit-Limit`: teto do balde mais apertado;
 *  - `RateLimit-Remaining`: o que ainda resta nele;
 *  - `RateLimit-Reset`: segundos até o balde renovar;
 *  - `RateLimit-Policy`: todos os baldes (`{teto};w={janela em segundos}`).
 *
 * Estourado: 429 (problem+json) com `Retry-After`.
 */
class ApiRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolver = RateLimiter::limiter('api');

        if ($resolver === null) {
            return $next($request);
        }

        $buckets = [];

        foreach ((array) $resolver($request) as $limit) {
            if ($limit instanceof Limit) {
                $buckets[] = [
                    'key' => 'api:'.$limit->key,
                    'max' => (int) $limit->maxAttempts,
                    'decay' => (int) $limit->decaySeconds,
                ];
            }
        }

        foreach ($buckets as $bucket) {
            if (RateLimiter::tooManyAttempts($bucket['key'], $bucket['max'])) {
                $retryAfter = RateLimiter::availableIn($bucket['key']);

                throw new ThrottleRequestsException('Too Many Attempts.', null, [
                    'Retry-After' => $retryAfter,
                    'RateLimit-Limit' => $bucket['max'],
                    'RateLimit-Remaining' => 0,
                    'RateLimit-Reset' => $retryAfter,
                    'RateLimit-Policy' => $this->policy($buckets),
                ]);
            }
        }

        foreach ($buckets as $bucket) {
            RateLimiter::hit($bucket['key'], $bucket['decay']);
        }

        $response = $next($request);

        $tightest = null;

        foreach ($buckets as $bucket) {
            $remaining = RateLimiter::remaining($bucket['key'], $bucket['max']);

            if ($tightest === null || $remaining < $tightest['remaining']) {
                $tightest = $bucket + ['remaining' => $remaining];
            }
        }

        if ($tightest !== null) {
            $response->headers->set('RateLimit-Limit', (string) $tightest['max']);
            $response->headers->set('RateLimit-Remaining', (string) max(0, $tightest['remaining']));
            $response->headers->set('RateLimit-Reset', (string) RateLimiter::availableIn($tightest['key']));
            $response->headers->set('RateLimit-Policy', $this->policy($buckets));
        }

        return $response;
    }

    /**
     * @param  list<array{key: string, max: int, decay: int}>  $buckets
     */
    private function policy(array $buckets): string
    {
        return implode(', ', array_map(
            static fn (array $bucket): string => $bucket['max'].';w='.$bucket['decay'],
            $buckets,
        ));
    }
}
