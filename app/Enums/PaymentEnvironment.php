<?php

namespace App\Enums;

enum PaymentEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Sandbox => 'Sandbox',
            self::Production => 'Produção',
        };
    }
}
