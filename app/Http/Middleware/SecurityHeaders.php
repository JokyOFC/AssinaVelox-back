<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança e Content-Security-Policy com nonce (config `assinavelox.security_headers`).
 *
 * - X-Content-Type-Options: nosniff; X-Frame-Options: DENY; Referrer-Policy strict-origin-when-cross-origin
 *   (no-referrer + X-Robots-Tag noindex em /assinar/* e /verificar/*).
 * - CSP: script-src 'self' + nonce (Vite::useCspNonce()); 'unsafe-inline' apenas em style-src
 *   (Tailwind/Radix injetam estilos inline); frame-ancestors 'none'; object-src 'none'.
 * - Em desenvolvimento com Vite "hot", a origem do dev server e o WebSocket do HMR são liberados.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $cspEnabled = (bool) config('assinavelox.security_headers.csp_enabled', true);
        $nonce = $cspEnabled ? Vite::useCspNonce() : null;

        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($this->isPublicSensitivePath($request)) {
            $headers->set('Referrer-Policy', 'no-referrer');
            $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        } else {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        if ($request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($cspEnabled && $nonce !== null) {
            $headerName = config('assinavelox.security_headers.csp_report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $headers->set($headerName, $this->policy($nonce));
        }

        return $response;
    }

    protected function isPublicSensitivePath(Request $request): bool
    {
        return $request->is('assinar', 'assinar/*', 'verificar', 'verificar/*');
    }

    protected function policy(string $nonce): string
    {
        $extra = $this->extraSources();
        $dev = $this->devServerSources();

        $scriptSources = array_merge(["'self'", "'nonce-{$nonce}'"], $extra, $dev['http']);
        $styleSources = array_merge(["'self'", "'unsafe-inline'"], $extra, $dev['http']);
        $fontSources = array_merge(["'self'", 'data:'], $extra, $dev['http']);
        $imgSources = array_merge(["'self'", 'data:', 'blob:'], $extra);
        $connectSources = array_merge(["'self'"], $extra, $dev['http'], $dev['ws']);

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', array_unique($scriptSources)),
            'style-src '.implode(' ', array_unique($styleSources)),
            'img-src '.implode(' ', array_unique($imgSources)),
            'font-src '.implode(' ', array_unique($fontSources)),
            'connect-src '.implode(' ', array_unique($connectSources)),
            "worker-src 'self' blob:",
            "frame-src 'none'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        return implode('; ', $directives);
    }

    /**
     * @return list<string>
     */
    protected function extraSources(): array
    {
        $raw = (string) config('assinavelox.security_headers.csp_extra_sources', '');

        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @return array{http: list<string>, ws: list<string>}
     */
    protected function devServerSources(): array
    {
        if (! app()->environment('local') || ! Vite::isRunningHot()) {
            return ['http' => [], 'ws' => []];
        }

        $hotFile = public_path('hot');
        $url = is_file($hotFile) ? rtrim((string) file_get_contents($hotFile)) : '';
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host'])) {
            return ['http' => [], 'ws' => []];
        }

        $scheme = $parts['scheme'] ?? 'http';
        $hostPort = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return [
            'http' => ["{$scheme}://{$hostPort}"],
            'ws' => [($scheme === 'https' ? 'wss' : 'ws')."://{$hostPort}"],
        ];
    }
}
