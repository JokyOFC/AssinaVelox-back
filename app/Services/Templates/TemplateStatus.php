<?php

namespace App\Services\Templates;

enum TemplateStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Archived => 'Arquivado',
        };
    }
}
