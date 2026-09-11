<?php

namespace App\Services\Templates;

/**
 * Tipos de variável de modelo (docs/fase-2/modelos.md §3). O tipo decide a validação no
 * servidor ({@see VariableValues}) e a formatação PT-BR ao inserir no documento
 * ({@see VariableFormatter}).
 */
enum VariableType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Currency = 'currency';
    case Date = 'date';
    case Cpf = 'cpf';
    case Cnpj = 'cnpj';
    case Email = 'email';
    case Phone = 'phone';
    case Select = 'select';
    case Boolean = 'boolean';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texto curto',
            self::LongText => 'Texto longo',
            self::Number => 'Número',
            self::Currency => 'Valor em reais',
            self::Date => 'Data',
            self::Cpf => 'CPF',
            self::Cnpj => 'CNPJ',
            self::Email => 'E-mail',
            self::Phone => 'Telefone',
            self::Select => 'Lista de opções',
            self::Boolean => 'Sim ou não',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
