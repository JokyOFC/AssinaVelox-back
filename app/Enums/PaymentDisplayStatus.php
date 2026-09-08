<?php

namespace App\Enums;

/**
 * Agrupamento de PaymentStatus somente para a UI (derivado, não persistido).
 */
enum PaymentDisplayStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public static function fromPaymentStatus(PaymentStatus $status): self
    {
        return match ($status) {
            PaymentStatus::Approved, PaymentStatus::Authorized => self::Paid,
            PaymentStatus::Pending, PaymentStatus::InProcess, PaymentStatus::InMediation => self::Pending,
            PaymentStatus::Rejected => self::Failed,
            PaymentStatus::Refunded, PaymentStatus::ChargedBack => self::Refunded,
            PaymentStatus::Cancelled => self::Cancelled,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paga',
            self::Pending => 'Pendente',
            self::Failed => 'Falhou',
            self::Refunded => 'Reembolsada',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * @return 'neutral'|'warning'|'info'|'success'|'danger'
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Pending => 'warning',
            self::Failed => 'danger',
            self::Refunded => 'info',
            self::Cancelled => 'neutral',
        };
    }
}
