<?php

namespace App\Enums;

enum DeliveryChannel: string
{
    case Email = 'email';
    case Sms = 'sms'; // Fase 2
    case Whatsapp = 'whatsapp'; // Fase 2

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::Sms => 'SMS',
            self::Whatsapp => 'WhatsApp',
        };
    }

    public function isAvailable(): bool
    {
        return $this === self::Email;
    }
}
