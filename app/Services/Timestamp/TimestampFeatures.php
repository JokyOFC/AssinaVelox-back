<?php

namespace App\Services\Timestamp;

use App\Services\Dossier\DossierFeature;

/**
 * Flags da onda C do K-TSA (roadmap T8). Nascem desligadas.
 *
 * - `operator_tsa`: liga a TSA da operadora (endpoint interno, carimbo do manifesto do
 *   dossiê, provedor `operator`). É da PLATAFORMA: só a chave global.
 * - `pades_bt`: permite aplicar o carimbo da TSA da operadora dentro da assinatura PAdES
 *   (tecnicamente B-T). Exige `operator_tsa`. NUNCA muda o perfil anunciado: a interface e
 *   `verification_records.signature_profile` continuam `PAdES-B-B` até o checklist T2
 *   ({@see PadesProfilePolicy::checklist()}).
 *
 * Contrato para `HandleInertiaRequests::features()` (fora da área do K-TSA): as chaves
 * `operator_tsa` e `pades_bt` devem vir daqui; `dossier_export` de
 * {@see DossierFeature::enabled()}.
 */
final class TimestampFeatures
{
    public const OPERATOR_TSA = 'operator_tsa';

    public const PADES_BT = 'pades_bt';

    public static function operatorTsa(): bool
    {
        return config('assinavelox.features.'.self::OPERATOR_TSA, false) === true;
    }

    public static function padesBt(): bool
    {
        return self::operatorTsa() && config('assinavelox.features.'.self::PADES_BT, false) === true;
    }
}
