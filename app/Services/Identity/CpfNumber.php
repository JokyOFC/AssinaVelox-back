<?php

namespace App\Services\Identity;

use App\Support\TaxId;

/**
 * CPF digitado pelo participante: dígitos, validação e as duas formas de exibição.
 *
 * - {@see self::format()} `123.456.789-09`: o valor gravado no campo e estampado no documento
 *   (é conteúdo do documento, preenchido pela própria pessoa).
 * - {@see self::mask()} `***.456.789-**`: a ÚNICA forma que sai em trilha, log, página de
 *   evidências e respostas fora do documento. Oculta os três primeiros dígitos e os dois
 *   verificadores — o mesmo padrão da Receita Federal para CPF de sócio nos dados abertos.
 *
 * Validar os dígitos prova só que o número é bem formado. Não prova que o CPF existe, que
 * está regular nem que pertence a quem digitou (docs/fase-2/identidade.md §2).
 */
final class CpfNumber
{
    public static function digits(?string $value): string
    {
        return TaxId::digits($value);
    }

    public static function isValid(?string $value): bool
    {
        return TaxId::isCpf($value);
    }

    public static function format(string $value): string
    {
        $digits = self::digits($value);

        return strlen($digits) === 11
            ? substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2)
            : $value;
    }

    public static function mask(?string $value): string
    {
        $digits = self::digits($value);

        if (strlen($digits) !== 11) {
            return '***.***.***-**';
        }

        return '***.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-**';
    }
}
