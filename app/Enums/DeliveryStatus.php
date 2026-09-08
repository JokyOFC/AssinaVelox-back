<?php

namespace App\Enums;

/**
 * "sent" significa aceito pelo provedor; "delivered" exige confirmação de entrega.
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Na fila',
            self::Sent => 'Enviado',
            self::Delivered => 'Entregue',
            self::Failed => 'Falhou',
            self::Bounced => 'Devolvido',
            self::Unknown => 'Desconhecido',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Bounced], true);
    }

    /**
     * @return 'neutral'|'warning'|'info'|'success'|'danger'
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Queued => 'neutral',
            self::Sent => 'info',
            self::Delivered => 'success',
            self::Failed, self::Bounced => 'danger',
            self::Unknown => 'warning',
        };
    }
}
