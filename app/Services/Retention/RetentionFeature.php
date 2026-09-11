<?php

namespace App\Services\Retention;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flag `retention_policies` (Fase 2 §2.19, roadmap T8) — nasce DESLIGADA.
 *
 * Mesma regra das demais flags da organização ({@see DomainFeatures::enabled()}): interruptor
 * global `assinavelox.features.retention_policies` (padrão false) E `plans.features` do plano.
 *
 * A flag liga a tela, a criação de bloqueios e a APLICAÇÃO da política. Bloqueios já criados
 * continuam valendo mesmo que a flag seja desligada depois: uma preservação legal não pode
 * evaporar por um interruptor de interface. Com a flag desligada desde sempre não existe
 * bloqueio nem política, e nada muda.
 *
 * Contrato para `HandleInertiaRequests::features()` (fora da área K-RET): a chave
 * `retention_policies` deve vir de {@see self::enabled()} para a organização corrente.
 */
final class RetentionFeature
{
    public const KEY = 'retention_policies';

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::KEY, $organization);
    }

    public static function ensure(?Organization $organization): void
    {
        abort_unless(self::enabled($organization), 404);
    }
}
