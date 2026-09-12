<?php

namespace App\Services\RestHooks;

use App\Models\Organization;
use App\Services\Api\ApiFeature;
use App\Services\Envelopes\DomainFeatures;
use App\Services\Webhooks\WebhooksFeature;

/**
 * Flag `rest_hooks` (roadmap §1 T8, §2.17) — DESLIGADA por padrão.
 *
 * Mesma regra das flags da organização (interruptor global E `plans.features.rest_hooks`) e,
 * além disso, depende das duas flags de que o recurso é feito:
 *
 *  - `api_integrations`: sem ela não há token nem rota `/api/v1`;
 *  - `outbound_webhooks`: sem ela o motor não cria nem entrega nada — aceitar uma assinatura
 *    que nunca recebe evento seria enganoso.
 *
 * Desligada: as rotas de assinatura respondem 404 (`not-found`), como recurso inexistente.
 */
final class RestHooksFeature
{
    public const KEY = 'rest_hooks';

    public static function globallyEnabled(): bool
    {
        return config('assinavelox.features.'.self::KEY, false) === true;
    }

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::KEY, $organization)
            && ApiFeature::enabled($organization)
            && WebhooksFeature::enabled($organization);
    }
}
