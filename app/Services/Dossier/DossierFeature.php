<?php

namespace App\Services\Dossier;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flag `dossier_export` (roadmap T8): exportação do dossiê ZIP e o "Baixar" em lote (Q12).
 * Mesma regra das flags da organização: interruptor global `assinavelox.features.dossier_export`
 * E `plans.features.dossier_export` do plano vigente. Desligada, as rotas novas respondem 404.
 */
final class DossierFeature
{
    public const FLAG = 'dossier_export';

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::FLAG, $organization);
    }
}
