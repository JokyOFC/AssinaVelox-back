<?php

namespace App\Enums;

enum FieldBoxType: string
{
    case CropBox = 'cropbox';
    case MediaBox = 'mediabox';

    public function label(): string
    {
        return match ($this) {
            self::CropBox => 'CropBox',
            self::MediaBox => 'MediaBox',
        };
    }
}
