<?php

namespace App\Enums;

enum DocumentVersionKind: string
{
    case Original = 'original';
    case Converted = 'converted';
    case Consolidated = 'consolidated';
    case Evidence = 'evidence';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Original => 'Original',
            self::Converted => 'Convertido',
            self::Consolidated => 'Consolidado',
            self::Evidence => 'Página de evidências',
            self::Final => 'Final',
        };
    }
}
