<?php

namespace App\Enums;

enum DocumentProcessingStatus: string
{
    case Uploaded = 'uploaded';
    case Converting = 'converting';
    case Ready = 'ready';
    case Failed = 'failed';
    case Blocked = 'blocked'; // PDF protegido, já assinado digitalmente ou inválido; original preservado

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Enviado',
            self::Converting => 'Convertendo…',
            self::Ready => 'Pronto',
            self::Failed => 'Falha ao processar',
            self::Blocked => 'Bloqueado',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Failed, self::Blocked], true);
    }

    /**
     * @return 'neutral'|'warning'|'info'|'success'|'danger'
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Uploaded => 'neutral',
            self::Converting => 'info',
            self::Ready => 'success',
            self::Failed, self::Blocked => 'danger',
        };
    }
}
