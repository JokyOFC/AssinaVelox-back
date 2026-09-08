<?php

namespace App\Enums;

enum RecipientRole: string
{
    case Signer = 'signer';
    // Fase 2: witness, approver, viewer

    public function label(): string
    {
        return match ($this) {
            self::Signer => 'Signatário',
        };
    }
}
