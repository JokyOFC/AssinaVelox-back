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
    /**
     * Fase 2 §2.11 (C-ID): CPF digitado pelo participante, validado pelos dígitos
     * verificadores no servidor. Dígitos válidos NÃO provam que a pessoa é a titular
     * (docs/fase-2/identidade.md §2). Criação no editor atrás da flag `cpf_field`.
     */
    case Cpf = 'cpf';
    /**
     * Fase 2 §2.8 (C-BRAND): carimbo visual da organização (logo + nome), desenhado como
     * imagem no PDF consolidado. Representação visual, não prova (docs/fase-2/branding.md).
     * Criação no editor atrás da flag `branding`.
     */
    case Stamp = 'stamp';

    public function label(): string
    {
        return match ($this) {
            self::Signature => 'Assinatura',
            self::Initials => 'Rubrica',
            self::Name => 'Nome completo',
            self::Date => 'Data',
            self::Text => 'Texto livre',
            self::Checkbox => 'Caixa de seleção',
            self::Cpf => 'CPF',
            self::Stamp => 'Carimbo visual',
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
