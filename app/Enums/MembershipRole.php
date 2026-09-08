<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Proprietário',
            self::Admin => 'Administrador',
            self::Member => 'Operador',
        };
    }

    /**
     * Peso para comparação hierárquica (owner > admin > member).
     */
    public function weight(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    public function isAtLeast(self $role): bool
    {
        return $this->weight() >= $role->weight();
    }

    public function canManageMembers(): bool
    {
        return $this->isAtLeast(self::Admin);
    }
}
