<?php

namespace App\Services\Envelopes\Steps;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flags da Fase 3 §3.3 (F-FLOW): `conditional_steps` e `delegation` — interruptor global
 * (`assinavelox.features.*`) E plano da organização, as duas desligadas por padrão (T8).
 *
 * A flag liga a INTERFACE e a criação de coisas novas (definir etapas, pedir delegação). Um
 * envelope já enviado com etapas continua sendo conduzido pelas etapas mesmo que a flag seja
 * desligada depois: desligar impede criar, nunca abandona um envelope no meio.
 */
final class FlowFeatures
{
    public const CONDITIONAL_STEPS = 'conditional_steps';

    public const DELEGATION = 'delegation';

    public static function conditionalSteps(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::CONDITIONAL_STEPS, $organization);
    }

    public static function delegation(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::DELEGATION, $organization);
    }

    /**
     * @return array{conditional_steps: bool, delegation: bool}
     */
    public static function forOrganization(?Organization $organization): array
    {
        return [
            self::CONDITIONAL_STEPS => self::conditionalSteps($organization),
            self::DELEGATION => self::delegation($organization),
        ];
    }

    public static function maxSteps(): int
    {
        return max(2, (int) config('assinavelox.flow.max_steps', 10));
    }

    public static function maxRules(): int
    {
        return max(1, (int) config('assinavelox.flow.max_rules_per_step', 10));
    }

    public static function maxLiteralLength(): int
    {
        return max(1, (int) config('assinavelox.flow.max_literal_length', 200));
    }
}
