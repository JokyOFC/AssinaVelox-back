<?php

namespace App\Enums;

enum SigningOrder: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';

    public function label(): string
    {
        return match ($this) {
            self::Sequential => 'Sequencial',
            self::Parallel => 'Todos ao mesmo tempo',
        };
    }
}
