<?php

namespace App\Enums;

enum PlanBillingPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensal',
            self::Yearly => 'Anual',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Yearly => 12,
        };
    }
}
