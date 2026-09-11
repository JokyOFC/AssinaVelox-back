<?php

namespace App\Integrations\Sms;

use App\Enums\DeliveryChannel;
use App\Integrations\Contracts\SmsProvider;

/**
 * Simulador IDENTIFICADO de SMS (Fase 2 §2.9). Nunca envia nada — ver
 * {@see SimulatedMessagingProvider}. Gravado em delivery_attempts.provider como
 * `sms_simulado`, e a interface recebe `simulated: true`.
 */
final class FakeSmsProvider extends SimulatedMessagingProvider implements SmsProvider
{
    public const NAME = 'sms_simulado';

    public function channel(): DeliveryChannel
    {
        return DeliveryChannel::Sms;
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function label(): string
    {
        return 'SMS';
    }
}
