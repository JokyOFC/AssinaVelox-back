<?php

namespace App\Services\Api;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flag `features.api_integrations` (roadmap §1 T8, §2.15) — DESLIGADA por padrão.
 *
 * Mesma regra das demais flags da organização: vale só quando o interruptor global
 * `assinavelox.features.api_integrations` E o plano vigente (`plans.features.api_integrations`)
 * dizem sim. Com o interruptor global desligado, `/api/v1/*` responde 404 antes de qualquer
 * autenticação; com ele ligado e o plano sem a flag, o token autentica e recebe 404.
 *
 * Contrato para `HandleInertiaRequests::features()` (fora da área D-API): a chave
 * `api_integrations` deve vir de {@see self::enabled()} para a organização corrente.
 */
final class ApiFeature
{
    public const KEY = 'api_integrations';

    public static function globallyEnabled(): bool
    {
        return (bool) config('assinavelox.features.'.self::KEY, false) === true;
    }

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::KEY, $organization);
    }
}
