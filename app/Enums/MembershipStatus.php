<?php

namespace App\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Suspended => 'Inativo',
        };
    }

    /**
     * @return 'neutral'|'warning'|'info'|'success'|'danger'
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'neutral',
        };
    }
}
