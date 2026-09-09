<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Support\TaxId;

/**
 * Dados de faturamento da organização (ROUTES §2.15).
 *
 * Onde cada coisa mora, e por quê:
 *
 * - **razão social** → `organizations.legal_name`, que já existe e é o mesmo dado da
 *   aba "Geral"; duplicar seria criar duas verdades sobre a mesma empresa;
 * - **CPF/CNPJ** → `organizations.tax_id`, coluna com cast `encrypted` e nunca
 *   indexada. Guardar o documento em JSON aberto seria um retrocesso de privacidade;
 * - **endereço, cidade, UF, CEP e e-mail** → `organizations.settings.billing_profile`,
 *   que é exatamente o lugar previsto para preferências da organização e não exige
 *   migração nova.
 *
 * O perfil só é considerado preenchido quando todos os campos existem — meio perfil no
 * recibo seria pior que nenhum.
 */
final class BillingProfile
{
    public const SETTINGS_KEY = 'billing_profile';

    /**
     * @return array{legal_name: string, document_number: string, address_line: string, city: string, state: string, postal_code: string, email: string}|null
     */
    public static function for(Organization $organization): ?array
    {
        $stored = $organization->settings[self::SETTINGS_KEY] ?? null;

        if (! is_array($stored)) {
            return null;
        }

        $profile = [
            'legal_name' => (string) ($organization->legal_name ?? ''),
            'document_number' => TaxId::digits($organization->tax_id),
            'address_line' => (string) ($stored['address_line'] ?? ''),
            'city' => (string) ($stored['city'] ?? ''),
            'state' => (string) ($stored['state'] ?? ''),
            'postal_code' => (string) ($stored['postal_code'] ?? ''),
            'email' => (string) ($stored['email'] ?? ''),
        ];

        foreach ($profile as $value) {
            if ($value === '') {
                return null;
            }
        }

        return $profile;
    }

    /**
     * @param  array{legal_name: string, document_number: string, address_line: string, city: string, state: string, postal_code: string, email: string}  $attributes
     */
    public static function store(Organization $organization, array $attributes): void
    {
        $settings = $organization->settings ?? [];
        $settings[self::SETTINGS_KEY] = [
            'address_line' => trim($attributes['address_line']),
            'city' => trim($attributes['city']),
            'state' => mb_strtoupper(trim($attributes['state'])),
            'postal_code' => TaxId::digits($attributes['postal_code']),
            'email' => mb_strtolower(trim($attributes['email'])),
        ];

        $organization->forceFill([
            'legal_name' => trim($attributes['legal_name']),
            'tax_id' => TaxId::digits($attributes['document_number']),
            'settings' => $settings,
        ])->save();
    }
}
