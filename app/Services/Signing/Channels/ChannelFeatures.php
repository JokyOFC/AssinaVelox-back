<?php

namespace App\Services\Signing\Channels;

use App\Models\Organization;
use App\Models\Plan;

/**
 * Flags da onda B, área de canais (roadmap §1 T8 — todas desligadas por padrão):
 *
 *  - `sms_whatsapp`: o remetente pode escolher código por SMS/WhatsApp e entrega do convite
 *    pelo canal (ainda depende de provedor disponível — ChannelAvailability);
 *  - `pin_auth`: o remetente pode definir um PIN por participante;
 *  - `sender_domains`: a organização pode cadastrar domínios de envio.
 *
 * Mesma regra das flags da onda A: interruptor global `assinavelox.features.{flag}` E
 * `plans.features.{flag}` do plano vigente. A flag liga a interface e as regras de preparo;
 * nunca abandona um envelope no meio — um participante que já tem `sms_otp` ou PIN continua
 * sendo atendido se a flag for desligada depois.
 *
 * Contrato para `HandleInertiaRequests::features()` (fora da área C-CAN): as chaves
 * `sms_whatsapp`, `pin_auth` e `sender_domains` devem vir de {@see self::forOrganization()}.
 */
final class ChannelFeatures
{
    public const SMS_WHATSAPP = 'sms_whatsapp';

    public const PIN_AUTH = 'pin_auth';

    public const SENDER_DOMAINS = 'sender_domains';

    public static function smsWhatsapp(?Organization $organization): bool
    {
        return self::enabled(self::SMS_WHATSAPP, $organization);
    }

    public static function pinAuth(?Organization $organization): bool
    {
        return self::enabled(self::PIN_AUTH, $organization);
    }

    public static function senderDomains(?Organization $organization): bool
    {
        return self::enabled(self::SENDER_DOMAINS, $organization);
    }

    /**
     * @return array{sms_whatsapp: bool, pin_auth: bool, sender_domains: bool}
     */
    public static function forOrganization(?Organization $organization): array
    {
        $plan = self::planOf($organization);

        return [
            self::SMS_WHATSAPP => self::enabled(self::SMS_WHATSAPP, $organization, $plan),
            self::PIN_AUTH => self::enabled(self::PIN_AUTH, $organization, $plan),
            self::SENDER_DOMAINS => self::enabled(self::SENDER_DOMAINS, $organization, $plan),
        ];
    }

    public static function enabled(string $flag, ?Organization $organization, ?Plan $plan = null): bool
    {
        if (config('assinavelox.features.'.$flag, false) !== true || $organization === null) {
            return false;
        }

        $plan ??= self::planOf($organization);

        return is_array($plan?->features) && ($plan->features[$flag] ?? false) === true;
    }

    private static function planOf(?Organization $organization): ?Plan
    {
        return $organization?->currentSubscription()->with('plan')->first()?->plan;
    }
}
