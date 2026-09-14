<?php

namespace App\Services\Affiliates;

use App\Rules\CpfOrCnpj;
use App\Support\TaxId;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Dados de repasse do afiliado (chave PIX + titular). Guardados CIFRADOS em repouso
 * (`affiliates.payout_details`, `encrypted:array`) e NUNCA exibidos por inteiro: telas, CSV
 * e trilha só recebem `mask()`. Nada disto vai para log, evento ou fila.
 *
 * O repasse em si acontece FORA da plataforma (o sistema calcula, não paga).
 */
final class PayoutDetails
{
    public const KEY_TYPES = ['cpf', 'cnpj', 'email', 'phone', 'random'];

    /**
     * Valida e normaliza a entrada do formulário.
     *
     * @param  array<string, mixed>  $input
     * @return array{method: string, pix_key_type: string, pix_key: string, holder_name: string, holder_tax_id: string}
     */
    public static function validate(array $input): array
    {
        $validated = Validator::make($input, [
            'pix_key_type' => ['required', Rule::in(self::KEY_TYPES)],
            'pix_key' => ['required', 'string', 'max:120'],
            'holder_name' => ['required', 'string', 'min:3', 'max:120'],
            'holder_tax_id' => ['required', 'string', 'max:20', new CpfOrCnpj],
        ], [], [
            'pix_key_type' => 'tipo de chave PIX',
            'pix_key' => 'chave PIX',
            'holder_name' => 'nome do titular',
            'holder_tax_id' => 'CPF/CNPJ do titular',
        ])->after(function ($validator) use ($input): void {
            $type = (string) ($input['pix_key_type'] ?? '');
            $key = trim((string) ($input['pix_key'] ?? ''));

            if ($key !== '' && ! self::keyMatchesType($type, $key)) {
                $validator->errors()->add('pix_key', 'A chave PIX não corresponde ao tipo escolhido.');
            }
        })->validate();

        $type = (string) $validated['pix_key_type'];

        return [
            'method' => 'pix',
            'pix_key_type' => $type,
            'pix_key' => self::normalizeKey($type, (string) $validated['pix_key']),
            'holder_name' => trim((string) $validated['holder_name']),
            'holder_tax_id' => TaxId::digits((string) $validated['holder_tax_id']),
        ];
    }

    private static function keyMatchesType(string $type, string $key): bool
    {
        $digits = preg_replace('/\D+/', '', $key) ?? '';

        return match ($type) {
            'cpf' => strlen($digits) === 11,
            'cnpj' => strlen($digits) === 14,
            'email' => filter_var($key, FILTER_VALIDATE_EMAIL) !== false,
            'phone' => strlen($digits) >= 10 && strlen($digits) <= 13,
            'random' => (bool) preg_match('/^[0-9a-fA-F]{8}-?[0-9a-fA-F]{4}-?[0-9a-fA-F]{4}-?[0-9a-fA-F]{4}-?[0-9a-fA-F]{12}$/', $key),
            default => false,
        };
    }

    private static function normalizeKey(string $type, string $key): string
    {
        $key = trim($key);

        return match ($type) {
            'cpf', 'cnpj', 'phone' => preg_replace('/\D+/', '', $key) ?? '',
            'email' => strtolower($key),
            default => strtolower($key),
        };
    }

    /**
     * Versão mascarada para exibição (portal, painel, CSV). Nunca devolve o valor inteiro.
     *
     * @param  array<string, mixed>|null  $details
     * @return array{method: string, pix_key_type: string, pix_key_type_label: string, pix_key: string, holder_name: string, holder_tax_id: string}|null
     */
    public static function mask(?array $details): ?array
    {
        if ($details === null || $details === []) {
            return null;
        }

        $type = (string) ($details['pix_key_type'] ?? '');
        $key = (string) ($details['pix_key'] ?? '');

        return [
            'method' => 'PIX',
            'pix_key_type' => $type,
            'pix_key_type_label' => self::keyTypeLabel($type),
            'pix_key' => $type === 'email' ? self::maskEmail($key) : self::maskTail($key, $type === 'random' ? 4 : 3),
            'holder_name' => self::maskName((string) ($details['holder_name'] ?? '')),
            'holder_tax_id' => self::maskTail((string) ($details['holder_tax_id'] ?? ''), 2),
        ];
    }

    public static function keyTypeLabel(string $type): string
    {
        return match ($type) {
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'email' => 'E-mail',
            'phone' => 'Telefone',
            'random' => 'Chave aleatória',
            default => $type,
        };
    }

    /** Mantém só os últimos `$visible` caracteres (nunca mais que um terço do valor). */
    public static function maskTail(string $value, int $visible): string
    {
        $length = mb_strlen($value);

        if ($length === 0) {
            return '';
        }

        $visible = min($visible, intdiv($length, 3));

        return str_repeat('•', $length - $visible).($visible > 0 ? mb_substr($value, -$visible) : '');
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $maskedLocal = mb_substr($local, 0, 1).'•••';
        $maskedDomain = $domain === '' ? '' : mb_substr($domain, 0, 1).'•••'.(str_contains($domain, '.') ? mb_substr($domain, (int) mb_strrpos($domain, '.')) : '');

        return $maskedLocal.'@'.$maskedDomain;
    }

    /** "Maria da Silva" → "Maria d•• S••••". */
    public static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return implode(' ', array_map(function (string $part, int $index): string {
            if ($index === 0) {
                return $part;
            }

            return mb_substr($part, 0, 1).str_repeat('•', max(1, mb_strlen($part) - 1));
        }, $parts, array_keys($parts)));
    }
}
