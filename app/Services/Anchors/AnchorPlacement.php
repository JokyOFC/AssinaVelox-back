<?php

namespace App\Services\Anchors;

/**
 * Onde o campo sugerido fica em relação ao texto encontrado (regra do modelo ou texto literal
 * da busca manual). Marcadores `{{tipo:papel}}` usam sempre `Over` (o campo cobre o marcador).
 */
enum AnchorPlacement: string
{
    case Below = 'below';
    case Right = 'right';
    case Above = 'above';
    case Over = 'over';

    public function label(): string
    {
        return match ($this) {
            self::Below => 'Abaixo do texto',
            self::Right => 'À direita do texto',
            self::Above => 'Acima do texto',
            self::Over => 'Sobre o texto',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
