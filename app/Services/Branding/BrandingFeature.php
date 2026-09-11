<?php

namespace App\Services\Branding;

use App\Models\Organization;

/**
 * Flag `branding` (Fase 2, roadmap §1 T8 e §2.8) — nasce DESLIGADA.
 *
 * Vale quando as DUAS fontes dizem sim, a mesma regra de DomainFeatures e ToolFlags:
 *  1. `config('assinavelox.features.branding')` — interruptor da operadora (padrão false);
 *  2. `plans.features.branding` do plano vigente da organização.
 *
 * A flag liga a interface (tela de marca, carimbo na paleta) e a APLICAÇÃO da marca em
 * e-mails, página pública e evidências. Não substitui a autorização: quem edita a marca é
 * decidido pela Policy (`updateSettings`) e pelo `org.role` da rota.
 *
 * Contrato para `HandleInertiaRequests::features()` (fora desta área): a chave `branding`
 * deve vir de {@see self::enabled()} para a organização corrente.
 */
final class BrandingFeature
{
    public const FLAG = 'branding';

    public static function enabled(?Organization $organization): bool
    {
        if (config('assinavelox.features.'.self::FLAG, false) !== true || $organization === null) {
            return false;
        }

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        return is_array($plan?->features) && ($plan->features[self::FLAG] ?? false) === true;
    }
}
