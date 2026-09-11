<?php

namespace App\Services\PublicForms;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;
use App\Services\Templates\TemplatesFeature;

/**
 * Flag `features.public_forms` (roadmap §1 T8, §2.2).
 *
 * Mesma regra das demais flags da Fase 2 ({@see DomainFeatures::enabled()}): vale só quando
 * a configuração global `assinavelox.features.public_forms` (padrão `false`) E o plano
 * vigente (`plans.features.public_forms = true`) dizem sim. Como o formulário gera o
 * documento a partir de um modelo (§2.2 depende de §2.1), a flag `templates` também precisa
 * estar ligada — sem ela os modelos nem aparecem para a organização.
 *
 * Desligada: TODAS as rotas do recurso — internas e públicas — respondem 404, e um link já
 * compartilhado passa a se comportar como um link inexistente.
 *
 * Contrato para `HandleInertiaRequests::features()` (fora da área deste agente): a chave
 * `public_forms` deve vir de {@see self::enabled()} para a organização corrente.
 */
final class PublicFormsFeature
{
    public const KEY = 'public_forms';

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::KEY, $organization) && TemplatesFeature::enabled($organization);
    }

    public static function ensure(?Organization $organization): void
    {
        abort_unless(self::enabled($organization), 404);
    }
}
