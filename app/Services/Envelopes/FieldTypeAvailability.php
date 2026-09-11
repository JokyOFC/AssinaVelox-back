<?php

namespace App\Services\Envelopes;

use App\Enums\FieldType;
use App\Models\Organization;
use App\Services\Branding\BrandingFeature;
use App\Services\Identity\IdentityFeatures;

/**
 * Tipos de campo que dependem de flag da Fase 2, onda B (roadmap §1 T8):
 *
 *  - `cpf` — flag `cpf_field` (C-ID, docs/fase-2/identidade.md §2);
 *  - `stamp` — flag `branding` (C-BRAND, docs/fase-2/branding.md §5).
 *
 * Com a flag desligada o servidor recusa CRIAR o campo (422 no campo), no editor do envelope
 * e no editor de modelos. Um campo que já existia (criado com a flag ligada) continua aceito
 * na mesma posição: desligar a flag impede coisas novas, nunca abandona um rascunho no meio.
 */
final class FieldTypeAvailability
{
    public static function allows(FieldType $type, ?Organization $organization): bool
    {
        return match ($type) {
            FieldType::Cpf => IdentityFeatures::cpfField($organization),
            FieldType::Stamp => BrandingFeature::enabled($organization),
            default => true,
        };
    }

    public static function unavailableMessage(FieldType $type): string
    {
        return match ($type) {
            FieldType::Cpf => 'O campo CPF não está disponível para esta organização.',
            FieldType::Stamp => 'O carimbo visual não está disponível para esta organização.',
            default => 'Tipo de campo inválido.',
        };
    }
}
