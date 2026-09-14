<?php

namespace App\Services\Signing\External;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Recipient;
use App\Services\Signing\Certificates\ParticipantA1Feature;

/**
 * Flag `a3_signing` (Fase 3 §3.4, roadmap T8). Nasce DESLIGADA.
 *
 * As duas fontes precisam dizer sim: `assinavelox.external_signing.enabled` (interruptor
 * global) e `plans.features.a3_signing` do plano vigente. A flag liga as rotas
 * `sign.external.*`. Um envelope com pedido já criado é conduzido até o fim mesmo que a flag
 * seja desligada depois (os pendentes vencem pelo prazo), como no A1 (§2.12).
 *
 * Pré-requisito do roadmap (§3): a Fase 3 pressupõe a Fase 2 em produção por um ciclo de
 * cobrança — aqui isso é condição de ATIVAÇÃO, não de código (docs/fase-3/assinatura-externa-a3.md §9).
 */
final class ExternalSigningFeature
{
    public const FLAG = 'a3_signing';

    public static function globallyEnabled(): bool
    {
        return filter_var(config('assinavelox.external_signing.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function enabledFor(?Organization $organization): bool
    {
        if (! self::globallyEnabled() || $organization === null) {
            return false;
        }

        /** @var Plan|null $plan */
        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        return ($plan?->features[self::FLAG] ?? false) === true;
    }

    /** Signatário e testemunha assinam; aprovador e visualizador não (mesma regra do A1). */
    public static function allowsRecipient(Recipient $recipient): bool
    {
        return ParticipantA1Feature::allowsRecipient($recipient);
    }

    public static function ttlMinutes(): int
    {
        return max(1, min(60, (int) config('assinavelox.external_signing.pending_ttl_minutes', 10)));
    }

    public static function lockWaitSeconds(): int
    {
        return max(0, min(60, (int) config('assinavelox.external_signing.lock_wait_seconds', 10)));
    }
}
