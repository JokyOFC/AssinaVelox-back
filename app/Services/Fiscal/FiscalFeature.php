<?php

namespace App\Services\Fiscal;

/**
 * Flag da PLATAFORMA `fiscal_invoices` (Fase 2, onda D — roadmap §2.21). Desligada (padrão), nada
 * muda: a tela de cobrança continua como na Fase 1 e nenhum job fiscal é despachado.
 */
final class FiscalFeature
{
    public const KEY = 'fiscal_invoices';

    public static function enabled(): bool
    {
        return config('assinavelox.features.'.self::KEY, false) === true;
    }
}
