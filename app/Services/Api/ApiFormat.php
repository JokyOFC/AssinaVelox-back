<?php

namespace App\Services\Api;

use Carbon\CarbonInterface;

/**
 * Convenções de formato da API v1 (docs/fase-2/api-v1.md §4): datas em ISO-8601 **UTC** com
 * sufixo `Z`; dinheiro em centavos inteiros + moeda ISO-4217; identificadores públicos ULID.
 */
final class ApiFormat
{
    public static function date(?CarbonInterface $value): ?string
    {
        return $value?->toIso8601ZuluString();
    }

    /**
     * @return array{amount_cents: int, currency: string}
     */
    public static function money(int $amountCents, string $currency = 'BRL'): array
    {
        return ['amount_cents' => $amountCents, 'currency' => strtoupper($currency)];
    }
}
