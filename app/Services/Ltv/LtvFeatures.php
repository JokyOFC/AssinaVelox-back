<?php

namespace App\Services\Ltv;

use App\Services\Timestamp\TimestampFeatures;

/**
 * Flags do P3-LTV (roadmap T8). Nascem desligadas e são da PLATAFORMA (só a chave global).
 *
 * - `pades_ltv`: liga a assinatura B-T/B-LT/B-LTA ({@see LtvSigner}), o estado técnico
 *   `verification_records.ltv_status` e o re-carimbo ({@see ArchiveTimestampRefresher}). Exige
 *   `operator_tsa` — a única TSA disponível é a da operadora. NUNCA muda o perfil anunciado.
 * - `pades_ltv_advertise`: separada. É a única que permite exibir um perfil além de PAdES-B-B, e
 *   só vale junto com `pades_ltv`. Ligar exige cumprir {@see LtvProfilePolicy::checklist()}.
 *
 * Contrato para `HandleInertiaRequests::features()` (fora da área): `pades_ltv` e
 * `pades_ltv_advertise` devem vir daqui.
 */
final class LtvFeatures
{
    public const PADES_LTV = 'pades_ltv';

    public const PADES_LTV_ADVERTISE = 'pades_ltv_advertise';

    public static function enabled(): bool
    {
        return TimestampFeatures::operatorTsa() && config('assinavelox.features.'.self::PADES_LTV, false) === true;
    }

    public static function advertise(): bool
    {
        return self::enabled() && config('assinavelox.features.'.self::PADES_LTV_ADVERTISE, false) === true;
    }
}
