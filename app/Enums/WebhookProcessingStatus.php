<?php

namespace App\Enums;

enum WebhookProcessingStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recebido',
            self::Processed => 'Processado',
            self::Ignored => 'Ignorado',
            self::Failed => 'Falhou',
        };
    }

    /**
     * @return 'neutral'|'warning'|'info'|'success'|'danger'
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Received => 'info',
            self::Processed => 'success',
            self::Ignored => 'neutral',
            self::Failed => 'danger',
        };
    }
}
