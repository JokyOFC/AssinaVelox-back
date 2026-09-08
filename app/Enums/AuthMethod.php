<?php

namespace App\Enums;

enum AuthMethod: string
{
    case EmailOtp = 'email_otp';
    // Fase 2: sms_otp, whatsapp_otp

    public function label(): string
    {
        return match ($this) {
            self::EmailOtp => 'Código por e-mail',
        };
    }
}
