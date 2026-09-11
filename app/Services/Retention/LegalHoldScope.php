<?php

namespace App\Services\Retention;

enum LegalHoldScope: string
{
    case Envelope = 'envelope';
    case Folder = 'folder';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Envelope => 'Documento',
            self::Folder => 'Pasta (e subpastas)',
            self::Organization => 'Organização inteira',
        };
    }
}
