<?php

namespace App\Services\BulkGeneration;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;
use App\Services\Templates\TemplatesFeature;

/**
 * Flag `features.bulk_generation` (roadmap §1 T8, §3.1).
 *
 * Mesma regra das flags de organização ({@see DomainFeatures::enabled()}): vale só quando a
 * configuração global `assinavelox.features.bulk_generation` (padrão `false`) E o plano vigente
 * (`plans.features.bulk_generation = true`) dizem sim. O lote gera envelopes a partir de modelos
 * (§2.1), então a flag `templates` também precisa estar ligada.
 *
 * Desligada: TODAS as rotas do recurso respondem 404 (middleware do controller, antes de
 * FormRequest e autorização), o botão "Gerar em lote" não aparece e nenhum job faz nada.
 */
final class BulkGenerationFeature
{
    public const KEY = 'bulk_generation';

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::KEY, $organization) && TemplatesFeature::enabled($organization);
    }

    public static function ensure(?Organization $organization): void
    {
        abort_unless(self::enabled($organization), 404);
    }
}
