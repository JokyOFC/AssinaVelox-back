<?php

namespace App\Services\Branding;

/**
 * Contraste de cores pela fórmula da WCAG 2.x (luminância relativa sRGB).
 *
 * Como as cores da marca são usadas (e por isso os mínimos):
 *
 * - **cor primária**: fundo do botão dos e-mails e da faixa do cabeçalho, com TEXTO BRANCO
 *   por cima. Exige {@see self::PRIMARY_MIN} (4,5:1, texto normal, WCAG 1.4.3 AA).
 * - **cor de destaque**: só elementos gráficos (bordas, filetes, moldura do carimbo) sobre
 *   fundo branco, nunca texto. Exige {@see self::ACCENT_MIN} (3:1, WCAG 1.4.11).
 *
 * Uma combinação abaixo do mínimo é recusada no servidor — a tela mostra a razão
 * calculada, mas quem decide é o backend.
 */
final class ColorContrast
{
    public const WHITE = '#FFFFFF';

    public const PRIMARY_MIN = 4.5;

    public const ACCENT_MIN = 3.0;

    /**
     * `#abc`, `abc`, `#aabbcc` ou `aabbcc` → `#AABBCC`. Qualquer outra coisa → null
     * (nada de nomes de cor, `rgb()`, transparência ou valores com espaço).
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $hex = ltrim(trim($value), '#');

        if (preg_match('/^[0-9A-Fa-f]{3}$/', $hex) === 1) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (preg_match('/^[0-9A-Fa-f]{6}$/', $hex) !== 1) {
            return null;
        }

        return '#'.strtoupper($hex);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        $normalized = self::normalize($hex) ?? '#000000';

        return [
            (int) hexdec(substr($normalized, 1, 2)),
            (int) hexdec(substr($normalized, 3, 2)),
            (int) hexdec(substr($normalized, 5, 2)),
        ];
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** "4,5:1" — uma casa decimal, arredondada para baixo (nunca aprova por arredondamento). */
    public static function format(float $ratio): string
    {
        return number_format(floor($ratio * 10) / 10, 1, ',', '').':1';
    }

    /**
     * Mensagem de recusa ou null quando a cor primária é legível com texto branco.
     */
    public static function primaryProblem(string $hex): ?string
    {
        $ratio = self::ratio($hex, self::WHITE);

        if ($ratio >= self::PRIMARY_MIN) {
            return null;
        }

        return sprintf(
            'Contraste insuficiente: o texto branco sobre %s fica com %s; o mínimo é %s. Escolha um tom mais escuro.',
            self::normalize($hex),
            self::format($ratio),
            self::format(self::PRIMARY_MIN),
        );
    }

    /**
     * Mensagem de recusa ou null quando a cor de destaque aparece sobre fundo branco.
     */
    public static function accentProblem(string $hex): ?string
    {
        $ratio = self::ratio($hex, self::WHITE);

        if ($ratio >= self::ACCENT_MIN) {
            return null;
        }

        return sprintf(
            'Contraste insuficiente: %s sobre fundo branco fica com %s; o mínimo é %s. Escolha um tom mais forte.',
            self::normalize($hex),
            self::format($ratio),
            self::format(self::ACCENT_MIN),
        );
    }
}
