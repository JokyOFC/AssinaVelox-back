<?php

namespace App\Services\Anchors;

enum AnchorScanStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Na fila',
            self::Running => 'Procurando',
            self::Done => 'Concluída',
            self::Failed => 'Falhou',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }
}
