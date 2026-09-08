<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Trialing = 'trialing'; // reservado (RECONCILIACAO Q4/Q8)
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Trialing => 'Em trial',
            self::Active => 'Ativo',
            self::PastDue => 'Inadimplente',
            self::Canceled => 'Cancelado',
            self::Expired => 'Expirado',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Canceled, self::Expired], true);
    }

    /**
     * Permite enviar envelopes? `past_due` bloqueia o envio (RECONCILIACAO Q20).
     */
    public function allowsSending(): bool
    {
        return in_array($this, [self::Active, self::Trialing], true);
    }

    /**
     * @return 'neutral'|'warning'|'info'|'success'|'danger'
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Trialing => 'info',
            self::Active => 'success',
            self::PastDue => 'danger',
            self::Canceled, self::Expired => 'neutral',
        };
    }
}
