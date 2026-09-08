<?php

namespace App\Rules;

use App\Support\TaxId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! TaxId::isCnpj($value)) {
            $fail('O campo :attribute deve ser um CNPJ válido.');
        }
    }
}
