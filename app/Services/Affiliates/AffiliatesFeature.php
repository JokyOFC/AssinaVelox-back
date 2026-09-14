<?php

namespace App\Services\Affiliates;

/**
 * Flag `features.affiliates` (Fase 3 §3.10). Da PLATAFORMA: só o interruptor global — o
 * programa de afiliados é da operadora, não de um plano de cliente. Nasce desligada
 * (roadmap §1 T8). Desligada, nada do programa existe: rotas 404, cadastro inalterado,
 * nenhuma comissão calculada.
 */
final class AffiliatesFeature
{
    public static function enabled(): bool
    {
        return (bool) config('assinavelox.features.affiliates', false);
    }
}
