<?php

namespace App\Support\Locale;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flag `multilingual` (Fase 3 §3.3 — F-I18N): interruptor global `assinavelox.features.multilingual`
 * E `plans.features.multilingual` do plano vigente, desligada por padrão (T8).
 *
 * Desligada, tudo é PT-BR exatamente como antes: nenhum idioma de participante é lido, nenhuma
 * prop nova sai para a página pública, os e-mails não mudam e as rotas novas respondem 404.
 * Um idioma já gravado num participante continua no banco, mas só volta a valer quando a flag
 * for ligada de novo.
 */
final class MultilingualFeature
{
    public const FLAG = 'multilingual';

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::FLAG, $organization);
    }
}
