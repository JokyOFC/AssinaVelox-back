<?php

namespace App\Enums;

/**
 * Derivado de accepted_at / revoked_at / expires_at (não persistido).
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Convite pendente',
            self::Accepted => 'Aceito',
            self::Expired => 'Expirado',
            self::Revoked => 'Revogado',
        };
    }

    /**
     * @return 'neutral'|'warning'|'info'|'success'|'danger'
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'success',
            self::Expired, self::Revoked => 'neutral',
        };
    }
}
