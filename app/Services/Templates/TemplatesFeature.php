<?php

namespace App\Services\Templates;

use App\Models\Organization;
use App\Policies\TemplatePolicy;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flag `features.templates` (roadmap §1 T8, §2.1).
 *
 * Mesma regra das demais flags da onda A ({@see DomainFeatures::enabled()}): vale só quando
 * a configuração global `assinavelox.features.templates` (padrão `false`) E o plano vigente
 * da organização (`plans.features.templates = true`) dizem sim.
 *
 * A flag liga a INTERFACE e as rotas de modelos; quem pode criar, editar ou usar continua
 * sendo decidido pela {@see TemplatePolicy}. Desligada: a tela Modelos volta a
 * ser o placeholder da Fase 1, as demais rotas respondem 404 e `envelopes.create?template=`
 * ignora o parâmetro (comportamento idêntico ao da Fase 1).
 *
 * Contrato para `HandleInertiaRequests::features()` (fora da área deste agente): a chave
 * `templates` deve vir de {@see self::enabled()} para a organização corrente.
 */
final class TemplatesFeature
{
    public const KEY = 'templates';

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::KEY, $organization);
    }

    /**
     * Rotas de modelos com a flag desligada: 404 (o recurso "não existe" para a organização).
     */
    public static function ensure(?Organization $organization): void
    {
        abort_unless(self::enabled($organization), 404);
    }
}
