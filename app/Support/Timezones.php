<?php

namespace App\Support;

/**
 * Fusos horários brasileiros oferecidos nas configurações da organização.
 */
final class Timezones
{
    /** @var array<string, string> identificador IANA => rótulo */
    public const BRAZIL = [
        'America/Sao_Paulo' => 'Brasília (GMT-3) — SP, RJ, MG, RS, PR, SC, ES, GO, DF...',
        'America/Fortaleza' => 'Fortaleza (GMT-3) — CE, MA, PI',
        'America/Recife' => 'Recife (GMT-3) — PE, PB, RN',
        'America/Maceio' => 'Maceió (GMT-3) — AL',
        'America/Bahia' => 'Salvador (GMT-3) — BA',
        'America/Belem' => 'Belém (GMT-3) — PA, AP',
        'America/Araguaina' => 'Araguaína (GMT-3) — TO',
        'America/Santarem' => 'Santarém (GMT-3) — oeste do PA',
        'America/Noronha' => 'Fernando de Noronha (GMT-2)',
        'America/Manaus' => 'Manaus (GMT-4) — AM',
        'America/Cuiaba' => 'Cuiabá (GMT-4) — MT',
        'America/Campo_Grande' => 'Campo Grande (GMT-4) — MS',
        'America/Porto_Velho' => 'Porto Velho (GMT-4) — RO',
        'America/Boa_Vista' => 'Boa Vista (GMT-4) — RR',
        'America/Rio_Branco' => 'Rio Branco (GMT-5) — AC',
        'America/Eirunepe' => 'Eirunepé (GMT-5) — sudoeste do AM',
    ];

    /**
     * Rótulo curto em PT-BR para frases ("horário de Brasília (GMT-3)"), no lugar do
     * identificador IANA. Fuso fora da lista: o identificador, para nunca esconder a
     * informação.
     */
    public static function humanLabel(?string $identifier): string
    {
        $label = self::BRAZIL[$identifier ?? ''] ?? null;

        if ($label === null) {
            return 'fuso '.($identifier !== null && $identifier !== '' ? $identifier : 'America/Sao_Paulo');
        }

        return 'horário de '.trim(explode(' — ', $label)[0]);
    }

    /**
     * @return array<int, string>
     */
    public static function identifiers(): array
    {
        return array_keys(self::BRAZIL);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys(self::BRAZIL),
            array_values(self::BRAZIL),
        );
    }
}
