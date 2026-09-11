<?php

namespace App\Services\Tags;

/**
 * Cores de etiqueta: lista FECHADA derivada da paleta do design (DESIGN_SYSTEM §1 — tons
 * primary, success, warning, danger, neutral e navy). O front mapeia cada valor para as
 * classes de token; nenhum hexadecimal vem do cliente.
 */
enum TagColor: string
{
    case Blue = 'blue';
    case Green = 'green';
    case Amber = 'amber';
    case Red = 'red';
    case Gray = 'gray';
    case Navy = 'navy';

    public function label(): string
    {
        return match ($this) {
            self::Blue => 'Azul',
            self::Green => 'Verde',
            self::Amber => 'Âmbar',
            self::Red => 'Vermelho',
            self::Gray => 'Cinza',
            self::Navy => 'Marinho',
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
            fn (self $color): array => ['value' => $color->value, 'label' => $color->label()],
            self::cases(),
        );
    }
}
