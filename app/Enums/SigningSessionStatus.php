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
            // O código prova a posse do canal, não a identidade (arquitetura §2, T1).
            self::PendingAuth => 'Aguardando confirmação do código',
            self::Authenticated => 'Código confirmado',
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
