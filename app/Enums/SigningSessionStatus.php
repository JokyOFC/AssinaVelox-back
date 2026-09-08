<?php

namespace App\Enums;

enum SigningSessionStatus: string
{
    case PendingAuth = 'pending_auth';
    case Authenticated = 'authenticated';
    case Consumed = 'consumed';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::PendingAuth => 'Aguardando confirmação de identidade',
            self::Authenticated => 'Identidade confirmada',
            self::Consumed => 'Concluída',
            self::Expired => 'Expirada',
            self::Revoked => 'Revogada',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Consumed, self::Expired, self::Revoked], true);
    }

    public function isUsable(): bool
    {
        return $this === self::Authenticated;
    }
}
