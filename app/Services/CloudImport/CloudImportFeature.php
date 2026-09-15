<?php

namespace App\Services\CloudImport;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flag `features.cloud_import` (Fase 3 §3.9, G-CONN; roadmap §1 T8) — DESLIGADA por padrão.
 *
 * Organização: vale quando o interruptor global `assinavelox.features.cloud_import` E o plano
 * vigente (`plans.features.cloud_import`) dizem sim. Desligada: as rotas de importação dão 404
 * e o wizard não mostra o cartão "Importar da nuvem".
 *
 * Ligada não quer dizer disponível: cada provedor ainda precisa do app registrado pelo
 * proprietário (classe B). Sem ele, a tela diz "aguardando app registrado pelo proprietário".
 *
 * Contrato para `HandleInertiaRequests::features()`: a chave `cloud_import` vem de
 * {@see self::enabled()}.
 */
final class CloudImportFeature
{
    public const KEY = 'cloud_import';

    public static function globallyEnabled(): bool
    {
        return (bool) config('assinavelox.features.'.self::KEY, false) === true;
    }

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::KEY, $organization);
    }
}
