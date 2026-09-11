<?php

namespace App\Services\InPerson;

use App\Models\Organization;
use App\Models\Plan;

/**
 * Flags da área C-PRES (Fase 2, onda B). Ambas nascem DESLIGADAS (T8).
 *
 * | Flag            | Liga                                                               |
 * |-----------------|--------------------------------------------------------------------|
 * | `in_person`     | abrir sessão presencial em tablet para um envelope enviado (§2.6)  |
 * | `batch_signing` | enviar e usar link de assinatura em lote (§2.7)                    |
 *
 * Mesma regra das demais áreas (DomainFeatures, IdentityFeatures): a flag da organização só
 * vale quando o interruptor global `assinavelox.features.{flag}` E `plans.features.{flag}`
 * do plano vigente dizem sim.
 *
 * Contrato para `HandleInertiaRequests::features()` (fora da área C-PRES): as duas chaves
 * devem vir de {@see self::forOrganization()} para a organização corrente.
 *
 * Desligar a flag encerra o que depende dela: a sessão presencial ativa deixa de aceitar
 * ações e o link de lote deixa de abrir. Os aceites já registrados continuam válidos (são
 * aceites comuns, gravados pelo mesmo `RecordAcceptance`).
 */
final class PresenceFeatures
{
    public const IN_PERSON = 'in_person';

    public const BATCH_SIGNING = 'batch_signing';

    public static function inPerson(?Organization $organization): bool
    {
        return self::enabled(self::IN_PERSON, $organization);
    }

    public static function batchSigning(?Organization $organization): bool
    {
        return self::enabled(self::BATCH_SIGNING, $organization);
    }

    /**
     * @return array{in_person: bool, batch_signing: bool}
     */
    public static function forOrganization(?Organization $organization): array
    {
        $plan = self::planOf($organization);

        return [
            self::IN_PERSON => self::enabled(self::IN_PERSON, $organization, $plan),
            self::BATCH_SIGNING => self::enabled(self::BATCH_SIGNING, $organization, $plan),
        ];
    }

    /**
     * Interruptor global, sem organização (páginas públicas que ainda não sabem de quem são).
     */
    public static function global(string $flag): bool
    {
        return (bool) config('assinavelox.features.'.$flag, false) === true;
    }

    public static function enabled(string $flag, ?Organization $organization, ?Plan $plan = null): bool
    {
        if (! self::global($flag) || $organization === null) {
            return false;
        }

        $plan ??= self::planOf($organization);

        return ($plan?->features[$flag] ?? false) === true;
    }

    private static function planOf(?Organization $organization): ?Plan
    {
        if ($organization === null) {
            return null;
        }

        /** @var Plan|null */
        return $organization->currentSubscription()->with('plan')->first()?->plan;
    }
}
