<?php

namespace App\Services\CloudImport;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Relaxamento da CSP SÓ na página de importação da nuvem (docs/fase-3/conectores.md §4.4).
 *
 * O Google Picker carrega script de `apis.google.com` e abre um iframe do Google; o Dropbox
 * Chooser carrega `dropins.js` e abre uma janela do Dropbox que conversa com a nossa por
 * `postMessage` — o que `Cross-Origin-Opener-Policy: same-origin` impede. O resto do app
 * continua com a política da Fase 1 (`frame-src 'none'`, COOP `same-origin`).
 *
 * Mecânica: o middleware da rota marca a requisição com {@see self::allow()}; o
 * SecurityHeaders chama {@see self::apply()} depois de montar a política, e só então as fontes
 * de `cloud_import.csp.sources` são acrescentadas (e `'none'` sai da diretiva que as recebe).
 * Sem a marca, `apply()` não faz nada. As listas são configuráveis porque os hosts exatos que
 * o Picker e o Chooser usam estão NÃO CONFIRMADOS na documentação: validar no primeiro teste
 * com os apps registrados.
 */
final class CloudImportCsp
{
    public const ATTRIBUTE = 'assinavelox.cloud_import_csp';

    public static function allow(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE, true);
    }

    public static function apply(Request $request, ResponseHeaderBag $headers): void
    {
        if ($request->attributes->get(self::ATTRIBUTE) !== true) {
            return;
        }

        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only'] as $name) {
            $policy = $headers->get($name);

            if (is_string($policy) && $policy !== '') {
                $headers->set($name, self::extend($policy));
            }
        }

        $coop = trim((string) config('assinavelox.cloud_import.csp.coop', 'same-origin-allow-popups'));

        if ($coop !== '' && $headers->has('Cross-Origin-Opener-Policy')) {
            $headers->set('Cross-Origin-Opener-Policy', $coop);
        }
    }

    public static function extend(string $policy): string
    {
        $extra = (array) config('assinavelox.cloud_import.csp.sources', []);
        $directives = [];

        foreach (explode(';', $policy) as $part) {
            $tokens = preg_split('/\s+/', trim($part)) ?: [];
            $name = strtolower((string) array_shift($tokens));

            if ($name !== '') {
                $directives[$name] = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
            }
        }

        foreach ($extra as $directive => $sources) {
            $directive = strtolower((string) $directive);
            $sources = array_values(array_filter(
                array_map('trim', array_filter((array) $sources, 'is_string')),
                static fn (string $source): bool => $source !== '' && preg_match('/^[a-z0-9.*:\/-]+$/i', $source) === 1,
            ));

            if ($sources === [] || ! preg_match('/^[a-z-]+$/', $directive)) {
                continue;
            }

            $current = array_values(array_filter($directives[$directive] ?? [], static fn (string $token): bool => $token !== "'none'"));
            $directives[$directive] = array_values(array_unique([...$current, ...$sources]));
        }

        $parts = [];

        foreach ($directives as $name => $tokens) {
            $parts[] = trim($name.' '.implode(' ', $tokens));
        }

        return implode('; ', $parts);
    }
}
