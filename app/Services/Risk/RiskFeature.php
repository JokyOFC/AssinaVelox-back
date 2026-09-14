<?php

namespace App\Services\Risk;

/**
 * Flag `antifraud` (Fase 3 §3.7) — da PLATAFORMA: só o interruptor global
 * `assinavelox.features.antifraud`, desligado por padrão (roadmap T8). O antifraude é da
 * operadora, não de um plano.
 *
 * Desligada: nenhum listener grava observação ou sinal, `RiskSignals::record()` não persiste,
 * nenhuma restrição é aplicada no envio (mesmo que uma organização tenha ficado `restricted`
 * enquanto a flag esteve ligada) e as rotas do painel e do pedido de revisão respondem 404.
 */
final class RiskFeature
{
    public static function enabled(): bool
    {
        return config('assinavelox.features.antifraud', false) === true;
    }

    /** Com `false`, o motor grava sinais e abre casos, mas nunca aplica `restricted` sozinho. */
    public static function autoRestrict(): bool
    {
        return config('assinavelox.risk.auto_restrict', true) === true;
    }
}
