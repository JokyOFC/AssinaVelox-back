<?php

namespace App\Enums;

enum FieldType: string
{
    case Signature = 'signature';
    case Initials = 'initials';
    case Name = 'name';
    case Date = 'date';
    case Text = 'text';
    case Checkbox = 'checkbox';
    // Fase 2: cpf, stamp

    public function label(): string
    {
        return match ($this) {
            self::Signature => 'Assinatura',
            self::Initials => 'Rubrica',
            self::Name => 'Nome completo',
            self::Date => 'Data',
            self::Text => 'Texto livre',
            self::Checkbox => 'Caixa de seleção',
        };
    }

    /**
     * Campos cujo valor é uma imagem (assinatura/rubrica) e não texto.
     */
    public function isImageBased(): bool
    {
        return in_array($this, [self::Signature, self::Initials], true);
    }

    /**
     * Campos preenchidos pelo servidor no aceite (não editáveis pelo signatário).
     */
    public function isServerFilled(): bool
    {
        return $this === self::Date;
    }
}
