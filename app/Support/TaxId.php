<?php

namespace App\Support;

/**
 * Utilitários de CPF/CNPJ: normalização, validação dos dígitos verificadores e máscara.
 * Não faz consulta externa (CnpjLookupProvider/CpfVerificationProvider são Fase 2).
 */
final class TaxId
{
    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function isCpf(?string $value): bool
    {
        $cpf = self::digits($value);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;

            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }

            $digit = ((10 * $sum) % 11) % 10;

            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function isCnpj(?string $value): bool
    {
        $cnpj = self::digits($value);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $weightsFirst = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weightsSecond = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $first = self::cnpjDigit(substr($cnpj, 0, 12), $weightsFirst);

        if ((int) $cnpj[12] !== $first) {
            return false;
        }

        $second = self::cnpjDigit(substr($cnpj, 0, 13), $weightsSecond);

        return (int) $cnpj[13] === $second;
    }

    /**
     * @param  array<int, int>  $weights
     */
    private static function cnpjDigit(string $base, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += (int) $base[$index] * $weight;
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }

    public static function isValid(?string $value): bool
    {
        return self::isCpf($value) || self::isCnpj($value);
    }

    /**
     * Formata 000.000.000-00 ou 00.000.000/0000-00; devolve o valor original se inválido.
     */
    public static function format(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = self::digits($value);

        return match (strlen($digits)) {
            11 => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits),
            14 => preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits),
            default => $value,
        };
    }

    /**
     * Máscara para exibição pública/evidências: 123.xxx.xxx-45 / 12.345.xxx/xxxx-90 (dígitos ocultos com asterisco).
     */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = self::digits($value);

        return match (strlen($digits)) {
            11 => substr($digits, 0, 3).'.***.***-'.substr($digits, 9, 2),
            14 => substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.***/****-'.substr($digits, 12, 2),
            default => str_repeat('*', max(0, strlen($digits) - 2)).substr($digits, -2),
        };
    }
}
