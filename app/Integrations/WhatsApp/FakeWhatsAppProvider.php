<?php

namespace App\Integrations\WhatsApp;

use App\Enums\DeliveryChannel;
use App\Integrations\Contracts\WhatsAppProvider;
use App\Integrations\Sms\SimulatedMessagingProvider;
use App\Integrations\Sms\SimulatedOutbox;

/**
 * Simulador IDENTIFICADO de WhatsApp (Fase 2 §2.9/§2.18). Nunca envia nada — ver
 * {@see SimulatedMessagingProvider}. Registra o template da finalidade e os parâmetros no
 * {@see SimulatedOutbox}; `delivery_attempts.provider = whatsapp_simulado`.
 */
final class FakeWhatsAppProvider extends SimulatedMessagingProvider implements WhatsAppProvider
{
    public const NAME = 'whatsapp_simulado';

    public function channel(): DeliveryChannel
    {
        return DeliveryChannel::Whatsapp;
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function label(): string
    {
        return 'WhatsApp';
    }
}
