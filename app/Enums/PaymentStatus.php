<?php

namespace App\Enums;

/**
 * Espelho fiel do campo `status` do Mercado Pago.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Authorized = 'authorized';
    case InProcess = 'in_process';
    case InMediation = 'in_mediation';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case ChargedBack = 'charged_back';

    public function label(): string
    {
        return $this->displayStatus()->label();
    }

    public function displayStatus(): PaymentDisplayStatus
    {
        return PaymentDisplayStatus::fromPaymentStatus($this);
    }

    public function isPaid(): bool
    {
        return $this->displayStatus() === PaymentDisplayStatus::Paid;
    }

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pending, self::InProcess, self::InMediation, self::Authorized], true);
    }

    /**
     * @return 'neutral'|'warning'|'info'|'success'|'danger'
     */
    public function badgeVariant(): string
    {
        return $this->displayStatus()->badgeVariant();
    }
}
