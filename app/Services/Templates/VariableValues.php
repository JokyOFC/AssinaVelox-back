<?php

namespace App\Services\Templates;

use App\Models\TemplateVariable;
use App\Support\TaxId;
use Illuminate\Validation\ValidationException;

/**
 * Validação e normalização, NO SERVIDOR, dos valores preenchidos para as variáveis de um
 * modelo (docs/fase-2/modelos.md §3). O navegador nunca decide o que é válido.
 *
 * Valores normalizados (o que segue para {@see VariableFormatter}):
 *  - text/long_text/email/select → string (e-mail em minúsculas);
 *  - number → float; currency → int (centavos); date → 'Y-m-d';
 *  - cpf/cnpj/phone → só dígitos; boolean → bool; vazio opcional → null.
 *
 * Todo valor é tratado como TEXTO: nada aqui (nem depois) é interpretado como código,
 * Blade, marcador de modelo ou HTML (roadmap T6).
 */
final class VariableValues
{
    public const TEXT_MAX = 500;

    public const LONG_TEXT_MAX = 5000;

    public const NUMBER_MAX_DECIMALS = 6;

    /** Teto de valores em reais: 10 trilhões de centavos, bem dentro de um int de 64 bits. */
    public const CURRENCY_MAX_CENTS = 10_000_000_000_000;

    /**
     * @param  iterable<TemplateVariable>  $variables
     * @param  array<string, mixed>  $input  valores por `key`
     * @return array<string, string|int|float|bool|null>
     *
     * @throws ValidationException com chaves `{$prefix}.{key}`
     */
    public function validate(iterable $variables, array $input, string $prefix = 'values'): array
    {
        $values = [];
        $errors = [];

        foreach ($variables as $variable) {
            // Chave ausente do envio → vale o valor padrão do modelo (também validado).
            $raw = array_key_exists($variable->key, $input) ? $input[$variable->key] : $variable->default_value;

            [$value, $error] = $this->normalize($variable->type, $variable->options ?? [], $raw, $variable->label, $variable->required);

            if ($error !== null) {
                $errors["{$prefix}.{$variable->key}"] = $error;

                continue;
            }

            $values[$variable->key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string|int|float|bool|null, 1: string|null}
     */
    public function normalize(VariableType $type, array $options, mixed $raw, string $label, bool $required): array
    {
        if ($type === VariableType::Boolean) {
            if ($raw === null || $raw === '') {
                return [false, null];
            }

            $bool = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            return $bool === null
                ? [null, sprintf('Escolha sim ou não em "%s".', $label)]
                : [$bool, null];
        }

        if (is_array($raw) || is_object($raw)) {
            return [null, sprintf('Valor inválido em "%s".', $label)];
        }

        $string = $raw === null ? '' : trim((string) $raw);

        if ($string === '') {
            return $required
                ? [null, sprintf('Preencha "%s".', $label)]
                : [null, null];
        }

        return match ($type) {
            VariableType::Text => $this->text($string, $options, $label, self::TEXT_MAX, multiline: false),
            VariableType::LongText => $this->text((string) $raw, $options, $label, self::LONG_TEXT_MAX, multiline: true),
            VariableType::Number => $this->number($string, $options, $label),
            VariableType::Currency => $this->currency($string, $options, $label),
            VariableType::Date => $this->date($string, $options, $label),
            VariableType::Cpf => TaxId::isCpf($string)
                ? [TaxId::digits($string), null]
                : [null, sprintf('Informe um CPF válido em "%s".', $label)],
            VariableType::Cnpj => TaxId::isCnpj($string)
                ? [TaxId::digits($string), null]
                : [null, sprintf('Informe um CNPJ válido em "%s".', $label)],
            VariableType::Email => $this->email($string, $label),
            VariableType::Phone => $this->phone($string, $label),
            VariableType::Select => $this->select($string, $options, $label),
        };
    }

    // -- Tipos --------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string|null, 1: string|null}
     */
    private function text(string $value, array $options, string $label, int $cap, bool $multiline): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        // Caracteres de controle nunca entram no documento (quebram XML/PDF); o texto longo
        // preserva as quebras de linha, o curto as troca por espaço.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = $multiline
            ? trim(preg_replace("/\n{3,}/", "\n\n", $value) ?? '')
            : trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        $max = min($cap, max(1, (int) ($options['max_length'] ?? $cap)));

        if (mb_strlen($value) > $max) {
            return [null, sprintf('"%s" aceita no máximo %d caracteres.', $label, $max)];
        }

        return [$value, null];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: float|null, 1: string|null}
     */
    private function number(string $value, array $options, string $label): array
    {
        $normalized = self::decimalString($value);

        if ($normalized === null) {
            return [null, sprintf('Informe um número válido em "%s".', $label)];
        }

        $decimals = min(self::NUMBER_MAX_DECIMALS, max(0, (int) ($options['decimals'] ?? 2)));
        $fraction = str_contains($normalized, '.') ? strlen(explode('.', $normalized)[1]) : 0;

        if ($fraction > $decimals) {
            return [null, $decimals === 0
                ? sprintf('"%s" aceita apenas números inteiros.', $label)
                : sprintf('"%s" aceita até %d casas decimais.', $label, $decimals)];
        }

        $number = (float) $normalized;

        if (isset($options['min']) && is_numeric($options['min']) && $number < (float) $options['min']) {
            return [null, sprintf('"%s" deve ser no mínimo %s.', $label, VariableFormatter::number((float) $options['min'], $decimals))];
        }

        if (isset($options['max']) && is_numeric($options['max']) && $number > (float) $options['max']) {
            return [null, sprintf('"%s" deve ser no máximo %s.', $label, VariableFormatter::number((float) $options['max'], $decimals))];
        }

        return [$number, null];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int|null, 1: string|null}
     */
    private function currency(string $value, array $options, string $label): array
    {
        $normalized = self::decimalString(preg_replace('/^R\$\s*/iu', '', $value) ?? '');

        if ($normalized === null || str_starts_with($normalized, '-')) {
            return [null, sprintf('Informe um valor em reais válido em "%s".', $label)];
        }

        [$integer, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        if (strlen($fraction) > 2) {
            return [null, sprintf('"%s" aceita no máximo 2 casas decimais (centavos).', $label)];
        }

        $integer = ltrim($integer, '0');

        if (strlen($integer) > 12) {
            return [null, sprintf('"%s" está acima do valor máximo aceito.', $label)];
        }

        $cents = ((int) ($integer === '' ? '0' : $integer)) * 100 + (int) str_pad($fraction, 2, '0');

        $min = isset($options['min_cents']) && is_numeric($options['min_cents']) ? (int) $options['min_cents'] : 0;
        $max = isset($options['max_cents']) && is_numeric($options['max_cents']) ? (int) $options['max_cents'] : self::CURRENCY_MAX_CENTS;

        if ($cents < $min) {
            return [null, sprintf('"%s" deve ser no mínimo %s.', $label, VariableFormatter::currency($min))];
        }

        if ($cents > $max) {
            return [null, sprintf('"%s" deve ser no máximo %s.', $label, VariableFormatter::currency($max))];
        }

        return [$cents, null];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string|null, 1: string|null}
     */
    private function date(string $value, array $options, string $label): array
    {
        $iso = self::isoDate($value);

        if ($iso === null) {
            return [null, sprintf('Informe uma data válida em "%s" (dd/mm/aaaa).', $label)];
        }

        $min = isset($options['min']) && is_string($options['min']) ? self::isoDate($options['min']) : null;
        $max = isset($options['max']) && is_string($options['max']) ? self::isoDate($options['max']) : null;

        if ($min !== null && $iso < $min) {
            return [null, sprintf('"%s" não pode ser anterior a %s.', $label, VariableFormatter::date($min))];
        }

        if ($max !== null && $iso > $max) {
            return [null, sprintf('"%s" não pode ser posterior a %s.', $label, VariableFormatter::date($max))];
        }

        return [$iso, null];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function email(string $value, string $label): array
    {
        $value = mb_strtolower($value);

        if (mb_strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return [null, sprintf('Informe um e-mail válido em "%s".', $label)];
        }

        return [$value, null];
    }

    /**
     * Telefone brasileiro: DDD + 8 ou 9 dígitos, com ou sem +55.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function phone(string $value, string $label): array
    {
        $digits = TaxId::digits($value);

        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if (! preg_match('/^[1-9][0-9](9?[0-9]{8})$/', $digits) || ! in_array(strlen($digits), [10, 11], true)) {
            return [null, sprintf('Informe um telefone válido com DDD em "%s".', $label)];
        }

        return [$digits, null];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string|null, 1: string|null}
     */
    private function select(string $value, array $options, string $label): array
    {
        $choices = array_values(array_filter(
            is_array($options['choices'] ?? null) ? $options['choices'] : [],
            'is_string',
        ));

        if (! in_array($value, $choices, true)) {
            return [null, sprintf('Escolha uma das opções de "%s".', $label)];
        }

        return [$value, null];
    }

    // -- Parsing -----------------------------------------------------------------------

    /**
     * "1.234,56" | "1234,56" | "1234.56" | "-12" → "1234.56" (ponto decimal, sem milhar).
     * Qualquer outra coisa → null.
     */
    public static function decimalString(string $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));

        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $value)) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (preg_match('/^-?\d+,\d+$/', $value)) {
            $value = str_replace(',', '.', $value);
        }

        if (! preg_match('/^-?\d{1,15}(\.\d{1,12})?$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * 'Y-m-d' ou 'd/m/Y' → 'Y-m-d', só para datas que existem (1900–2200).
     */
    public static function isoDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        if ($year < 1900 || $year > 2200 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
