<?php

namespace App\Services\Branding\Stamp;

use App\Enums\FieldType;
use App\Models\Envelope;
use App\Models\SigningField;

/**
 * Como o carimbo é descrito na página de evidências (T1, arquitetura §2).
 *
 * Contrato para `EvidenceData::build()` (fora desta área):
 * `'stamp' => StampEvidence::forEnvelope($envelope, $sentVersion?->getKey())`.
 * O template (`resources/views/evidence/page.blade.php`) só mostra a linha quando a chave
 * existe e não é nula; sem carimbo, a página não muda.
 */
final class StampEvidence
{
    public const DESCRIPTION = 'Carimbo visual da organização — representação visual, não prova.';

    /**
     * @return array{description: string, count: int}|null
     */
    public static function forEnvelope(Envelope $envelope, ?int $documentVersionId = null): ?array
    {
        $count = SigningField::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('type', FieldType::Stamp->value)
            ->when($documentVersionId !== null, fn ($query) => $query->where('document_version_id', $documentVersionId))
            ->count();

        return $count > 0 ? ['description' => self::DESCRIPTION, 'count' => $count] : null;
    }
}
