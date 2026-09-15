<?php

namespace App\Services\Embed;

use App\Models\Organization;
use App\Services\Api\ApiFeature;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flag `features.embedded_signing` (Fase 3 §3.9, roadmap T8) — DESLIGADA por padrão.
 *
 * Mesma regra das demais flags da organização: vale só quando o interruptor global
 * `assinavelox.features.embedded_signing` E o plano vigente (`plans.features.embedded_signing`)
 * dizem sim — E a API v1 (`api_integrations`) está ligada para a organização: a sessão só é
 * criada pela API, e oferecer o widget sem ela prometeria o que não abre (revisão adversarial
 * da onda G: "API e integrações" mostrava o widget ao lado de "a API ainda não existe").
 *
 * Desligada: a rota da API responde 404, `/embed/*` responde 404 (com os cabeçalhos de sempre,
 * `X-Frame-Options: DENY` e `frame-ancestors 'none'`), o `embed.js` responde 404 e a tela de
 * origens permitidas não existe.
 */
final class EmbedFeature
{
    public const KEY = 'embedded_signing';

    public static function globallyEnabled(): bool
    {
        return (bool) config('assinavelox.features.'.self::KEY, false) === true;
    }

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::KEY, $organization) && ApiFeature::enabled($organization);
    }
}
