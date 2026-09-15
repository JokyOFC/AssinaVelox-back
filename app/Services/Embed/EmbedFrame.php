<?php

namespace App\Services\Embed;

use Illuminate\Http\Request;

/**
 * Contrato entre as rotas `/embed/*` e o middleware App\Http\Middleware\SecurityHeaders
 * (docs/fase-3/widget-embutido.md §5).
 *
 * O resto do app continua com `X-Frame-Options: DENY` e `frame-ancestors 'none'`. Só a página
 * do widget, e só depois de o controller achar a sessão, marca a requisição com a origem EXATA
 * que pode enquadrá-la; aí o middleware omite o `X-Frame-Options` (que não aceita origem e, com
 * `DENY`, contradiria a CSP) e emite `frame-ancestors {origem}`. O `embed.js` servido com
 * sucesso é marcado para receber `Cross-Origin-Resource-Policy: cross-origin` — é um script que
 * o site do cliente carrega de outra origem.
 */
final class EmbedFrame
{
    public const ANCESTOR_ATTRIBUTE = 'assinavelox.embed.frame_ancestor';

    public const SCRIPT_ATTRIBUTE = 'assinavelox.embed.script';

    /** Versão do protocolo de mensagens `assinavelox:*` (campo `v`). */
    public const PROTOCOL_VERSION = 1;

    public static function allowFramingBy(Request $request, string $origin): void
    {
        $request->attributes->set(self::ANCESTOR_ATTRIBUTE, $origin);
    }

    public static function markScript(Request $request): void
    {
        $request->attributes->set(self::SCRIPT_ATTRIBUTE, true);
    }

    /**
     * Origem que pode enquadrar ESTA resposta — só em `/embed/*` e só uma origem canônica
     * válida. Qualquer outra situação devolve `null` e vale a regra geral (DENY).
     */
    public static function frameAncestor(Request $request): ?string
    {
        if (! $request->is('embed/*')) {
            return null;
        }

        $origin = $request->attributes->get(self::ANCESTOR_ATTRIBUTE);

        return is_string($origin) ? AllowedOrigins::normalize($origin) : null;
    }

    public static function isScript(Request $request): bool
    {
        return $request->is('embed/*') && $request->attributes->get(self::SCRIPT_ATTRIBUTE) === true;
    }
}
