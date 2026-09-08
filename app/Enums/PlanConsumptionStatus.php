<?php

namespace App\Enums;

enum PlanConsumptionStatus: string
{
    case Reserved = 'reserved';
    case Committed = 'committed';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reservado',
            self::Committed => 'Consumido',
            self::Released => 'Liberado',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Reserved;
    }
}
