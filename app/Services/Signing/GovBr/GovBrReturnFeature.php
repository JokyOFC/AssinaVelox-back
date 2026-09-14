<?php

namespace App\Services\Signing\GovBr;

use App\Enums\AcceptanceAction;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Recipient;

/**
 * Flag `govbr_return` (Fase 3 §3.5, P3-GOV; roadmap T8). Nasce DESLIGADA.
 *
 * Três condições, todas necessárias:
 *
 * 1. `assinavelox.govbr.return_enabled` — interruptor global da instalação;
 * 2. `assinavelox.govbr.finalizer_integration` — TRAVA de integração: só pode ser ligada
 *    depois que o `EnvelopeFinalizer` consultar {@see GovBrReturnStage} (esperar pelos
 *    pedidos, contar as assinaturas devolvidas na cadeia, mapear o `signature_status`). Sem
 *    isso, uma revisão devolvida entraria na cadeia sem ser contada e a finalização recusaria
 *    o arquivo (docs/fase-3/gov-br.md §8);
 * 3. `plans.features.govbr_return` do plano vigente da organização.
 *
 * Pré-requisitos de produção (docs/fase-3/gov-br.md §9): fixture real do portal conferida no
 * VALIDAR, raiz gov.br fixada por impressão digital, atualização incremental confirmada,
 * mapeamento do CPF no certificado e a Fase 2 em produção por um ciclo de cobrança.
 */
final class GovBrReturnFeature
{
    public const FLAG = 'govbr_return';

    public static function globallyEnabled(): bool
    {
        return filter_var(config('assinavelox.govbr.return_enabled', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(config('assinavelox.govbr.finalizer_integration', false), FILTER_VALIDATE_BOOLEAN);
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

    /**
     * Só signatário e testemunha registram aceite que pode receber assinatura; aprovador e
     * visualizador não (Fase 2 §2.4).
     */
    public static function allowsRecipient(Recipient $recipient): bool
    {
        return in_array($recipient->role->acceptanceAction(), [AcceptanceAction::Sign, AcceptanceAction::Witness], true);
    }
}
