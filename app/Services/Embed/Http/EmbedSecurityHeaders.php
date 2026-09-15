<?php

namespace App\Services\Embed\Http;

use App\Services\Embed\EmbedFrame;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Ajuste dos cabeçalhos de segurança SÓ nas rotas `/embed/*` (docs/fase-3/widget-embutido.md
 * §5), chamado no fim de App\Http\Middleware\SecurityHeaders. Fora de `/embed/*` não faz nada.
 *
 * - Toda resposta `/embed/*`: `Referrer-Policy: no-referrer` e `X-Robots-Tag: noindex`.
 * - `embed.js` servido com sucesso: `Cross-Origin-Resource-Policy: cross-origin` (é carregado
 *   pelo site do cliente, de outra origem).
 * - Página do widget com sessão encontrada ({@see EmbedFrame::frameAncestor()}):
 *   `X-Frame-Options` REMOVIDO (só aceita DENY/SAMEORIGIN e contradiria a CSP),
 *   `frame-ancestors {origem exata}` no lugar de `'none'` e `blob:` em `connect-src` (o PDF é
 *   baixado com o token no cabeçalho e entregue ao PDF.js como `blob:`). Se a CSP estiver
 *   desligada ou em report-only pela configuração, uma CSP APLICADA só com `frame-ancestors` é
 *   emitida mesmo assim: report-only não bloqueia o enquadramento, e sem `X-Frame-Options`
 *   a página ficaria enquadrável por qualquer site.
 * - Qualquer outra resposta `/embed/*` (JSON, 404, sessão inexistente): continua DENY + 'none'.
 */
final class EmbedSecurityHeaders
{
    public static function apply(Request $request, ResponseHeaderBag $headers): void
    {
        if (! $request->is('embed/*')) {
            return;
        }

        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        if (EmbedFrame::isScript($request)) {
            $headers->set('Cross-Origin-Resource-Policy', 'cross-origin');

            return;
        }

        $origin = EmbedFrame::frameAncestor($request);

        if ($origin === null) {
            return;
        }

        $headers->remove('X-Frame-Options');

        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only'] as $name) {
            $policy = $headers->get($name);

            if (is_string($policy) && trim($policy) !== '') {
                $headers->set($name, self::rewrite($policy, $origin));
            }
        }

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors {$origin}");
        }

        $headers->set('Cache-Control', 'no-store, private');
    }

    /**
     * Troca `frame-ancestors` pela origem exata e acrescenta `blob:` a `connect-src`.
     */
    public static function rewrite(string $policy, string $origin): string
    {
        $directives = [];
        $ancestors = false;

        foreach (explode(';', $policy) as $directive) {
            $directive = trim($directive);

            if ($directive === '') {
                continue;
            }

            $name = strtolower((string) strtok($directive, " \t"));

            if ($name === 'frame-ancestors') {
                $directives[] = "frame-ancestors {$origin}";
                $ancestors = true;

                continue;
            }

            if ($name === 'connect-src' && ! preg_match('/(^|\s)blob:(\s|$)/', $directive)) {
                $directives[] = $directive.' blob:';

                continue;
            }

            $directives[] = $directive;
        }

        if (! $ancestors) {
            $directives[] = "frame-ancestors {$origin}";
        }

        return implode('; ', $directives);
    }
}
