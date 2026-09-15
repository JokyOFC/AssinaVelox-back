<?php

namespace App\Services\HubSpot;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flag `features.hubspot` (Fase 3 §3.9, G-CONN; roadmap §1 T8) — DESLIGADA por padrão.
 *
 * Organização: interruptor global `assinavelox.features.hubspot` E plano vigente. Desligada:
 * a tela, a conexão, o retorno do OAuth e o endpoint da ação de workflow respondem 404, e o
 * gancho de atualização do negócio/contato não faz nada.
 *
 * Classe B: ligada, a tela ainda diz "aguardando app registrado pelo proprietário" enquanto
 * `services.hubspot.client_id`/`client_secret` não existirem. O cartão de CRM (app card) é
 * classe C e não tem código (docs/fase-3/trilha-bloqueada.md §3).
 */
final class HubSpotFeature
{
    public const KEY = 'hubspot';

    public static function globallyEnabled(): bool
    {
        return (bool) config('assinavelox.features.'.self::KEY, false) === true;
    }

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::KEY, $organization);
    }
}
