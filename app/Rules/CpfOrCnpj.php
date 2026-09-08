<?php

namespace App\Rules;

use App\Support\TaxId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Aceita CPF (11 dígitos) ou CNPJ (14 dígitos), com ou sem máscara, validando os
 * dígitos verificadores (RECONCILIACAO Q3: autônomos podem usar CPF).
 */
class CpfOrCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('O campo :attribute deve ser um CPF ou CNPJ válido.');

            return;
        }

        $digits = TaxId::digits($value);

        if (strlen($digits) === 11) {
            if (! TaxId::isCpf($digits)) {
                $fail('O campo :attribute deve ser um CPF válido.');
            }

            return;
        }

        if (strlen($digits) === 14) {
            if (! TaxId::isCnpj($digits)) {
                $fail('O campo :attribute deve ser um CNPJ válido.');
            }

            return;
        }

        $fail('O campo :attribute deve ter 11 dígitos (CPF) ou 14 dígitos (CNPJ).');
    }
}
