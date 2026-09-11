<?php

namespace App\Enums;

enum DeliveryChannel: string
{
    case Email = 'email';
    case Sms = 'sms'; // Fase 2 §2.9 — contrato + simulador (docs/fase-2/canais-e-pin.md)
    case Whatsapp = 'whatsapp'; // Fase 2 §2.9/§2.18 — contrato + simulador

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::Sms => 'SMS',
            self::Whatsapp => 'WhatsApp',
        };
    }

    /**
     * Disponibilidade INCONDICIONAL: só o e-mail. SMS e WhatsApp dependem da flag
     * `sms_whatsapp` da organização e de um provedor disponível — quem responde isso é
     * App\Services\Signing\Channels\ChannelAvailability, nunca este enum.
     */
    public function isAvailable(): bool
    {
        return $this === self::Email;
    }

    /**
     * O canal entrega em um número de celular (E.164), não em um e-mail.
     */
    public function requiresPhone(): bool
    {
        return $this !== self::Email;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
