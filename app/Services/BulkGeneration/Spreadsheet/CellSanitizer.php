<?php

namespace App\Services\BulkGeneration\Spreadsheet;

use App\Services\Templates\VariableValues;
use DateTimeInterface;

/**
 * Converte o conteúdo de uma célula em TEXTO literal (T6). Nada é avaliado: o texto segue
 * depois para a validação por tipo da variável ({@see VariableValues}).
 *
 * Regras de recusa (a linha fica com erro na coluna, se a coluna estiver mapeada):
 *  - célula de fórmula no XLSX ({@see RejectedCell::formula()}) — nem a fórmula nem o valor
 *    em cache são usados;
 *  - texto que começa com "=" ou "@" (injeção de fórmula, CWE-1236);
 *  - texto que começa com "+" ou "-" e NÃO é numérico (telefone "+55 11…", número "-12" e
 *    data passam; "+cmd|' /C calc'!A0" e "-2+3+cmd|…" não);
 *  - texto acima do limite de caracteres por célula.
 */
final class CellSanitizer
{
    /** O que sobra depois de "+" ou "-" em um valor numérico legítimo. */
    private const NUMERIC_TAIL = '/^[+-][0-9\s().,\/-]*$/u';

    public static function text(string $raw, int $maxChars): string|RejectedCell
    {
        // Caracteres de controle (exceto TAB, CR e LF) nunca seguem adiante.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw);

        if ($value === null) {
            // UTF-8 inválido depois da conversão: trata como texto vazio recusado.
            return RejectedCell::unsupported();
        }

        // Aparar por CARACTERE (regex Unicode), nunca pela lista de bytes do trim(): "\u{00A0}"
        // viraria os bytes C2 e A0 soltos e cortaria "¿", "«", "§", "à"… ao meio (UTF-8 inválido).
        $value = preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $value);

        if ($value === null || ! mb_check_encoding($value, 'UTF-8')) {
            return RejectedCell::unsupported();
        }

        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) > $maxChars) {
            return RejectedCell::tooLong($maxChars);
        }

        $first = $value[0];

        if ($first === '=' || $first === '@') {
            return RejectedCell::formulaLike();
        }

        if (($first === '+' || $first === '-') && ! preg_match(self::NUMERIC_TAIL, $value)) {
            return RejectedCell::formulaLike();
        }

        return $value;
    }

    /**
     * Número lido do XLSX → texto com ponto decimal, sem notação científica
     * (VariableValues::decimalString aceita "1234.5").
     */
    public static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_finite($value)) {
            return '';
        }

        if (floor($value) === $value && abs($value) < 1e15) {
            return number_format($value, 0, '.', '');
        }

        $text = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    public static function date(DateTimeInterface $value): string
    {
        return $value->format('Y-m-d');
    }

    /**
     * Rótulo de cabeçalho para exibição e sugestão de mapeamento: sem controle, sem quebra,
     * até 120 caracteres. Nunca é interpretado.
     */
    public static function header(string $raw): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $raw) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, 120);
    }
}
