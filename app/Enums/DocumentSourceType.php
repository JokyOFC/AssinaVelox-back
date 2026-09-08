<?php

namespace App\Enums;

enum DocumentSourceType: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';
    case Image = 'image';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Docx => 'Word (DOCX)',
            self::Image => 'Imagem',
        };
    }

    public function requiresConversion(): bool
    {
        return $this !== self::Pdf;
    }
}
