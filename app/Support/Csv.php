<?php

namespace App\Support;

/**
 * Escrita segura de CSV para as exportações.
 *
 * `fputcsv()` escapa delimitador e aspas, mas NÃO neutraliza células que começam com
 * `=`, `+`, `-`, `@`, TAB ou CR: Excel e LibreOffice tratam essas células como fórmula ao
 * abrir o arquivo (CWE-1236, "CSV formula injection"). `Csv::cell()` prefixa um apóstrofo,
 * que os dois aplicativos consomem ao exibir o valor.
 *
 * Uso: `fputcsv($out, Csv::row([...]), ';')`.
 */
final class Csv
{
    /** Prefixos que disparam a interpretação como fórmula. */
    public const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * BOM UTF-8, para o Excel em português abrir o arquivo com os acentos corretos.
     */
    public const BOM = "\xEF\xBB\xBF";

    public static function cell(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return $value === true ? 'Sim' : ($value === false ? 'Não' : '');
        }

        $text = (string) $value;

        if ($text === '') {
            return '';
        }

        return in_array($text[0], self::DANGEROUS_PREFIXES, true) ? "'".$text : $text;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    public static function row(array $values): array
    {
        return array_map(self::cell(...), $values);
    }
}
