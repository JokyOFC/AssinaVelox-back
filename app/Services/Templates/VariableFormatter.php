<?php

namespace App\Services\Templates;

/**
 * Formatação PT-BR dos valores normalizados por {@see VariableValues} para inserção no
 * documento. Devolve TEXTO PURO: quem insere (HTML ou DOCX) é quem escapa.
 */
final class VariableFormatter
{
    /**
     * @param  array<string, mixed>  $options
     */
    public static function format(VariableType $type, array $options, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            VariableType::Number => self::number(
                (float) $value,
                isset($options['decimals']) ? max(0, min(VariableValues::NUMBER_MAX_DECIMALS, (int) $options['decimals'])) : self::decimalsOf((float) $value),
            ),
            VariableType::Currency => self::currency((int) $value),
            VariableType::Date => self::date((string) $value),
            VariableType::Cpf => self::cpf((string) $value),
            VariableType::Cnpj => self::cnpj((string) $value),
            VariableType::Phone => self::phone((string) $value),
            VariableType::Boolean => $value ? 'Sim' : 'Não',
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    public static function number(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    public static function currency(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    public static function date(string $iso): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return $iso;
        }

        return $m[3].'/'.$m[2].'/'.$m[1];
    }

    public static function cpf(string $digits): string
    {
        return strlen($digits) === 11
            ? substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2)
            : $digits;
    }

    public static function cnpj(string $digits): string
    {
        return strlen($digits) === 14
            ? substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2)
            : $digits;
    }

    public static function phone(string $digits): string
    {
        return match (strlen($digits)) {
            11 => '('.substr($digits, 0, 2).') '.substr($digits, 2, 5).'-'.substr($digits, 7, 4),
            10 => '('.substr($digits, 0, 2).') '.substr($digits, 2, 4).'-'.substr($digits, 6, 4),
            default => $digits,
        };
    }

    private static function decimalsOf(float $value): int
    {
        return floor($value) === $value ? 0 : 2;
    }
}
