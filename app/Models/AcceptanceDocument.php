<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Parte de um aceite relativa a UM documento do envelope (Fase 2 §2.3).
 *
 * Um aceite por participante cobre o conjunto; aqui fica, por documento, a versão exata
 * apresentada, o SHA-256 dela e o snapshot dos campos daquele documento com os valores
 * gravados. Imutável depois de criado (append-only, como o aceite).
 *
 * @property int $id
 * @property int $signature_acceptance_id
 * @property int $document_id
 * @property int $document_version_id
 * @property int $organization_id
 * @property int $position
 * @property string $document_sha256
 * @property array<string, mixed>|null $fields_snapshot
 * @property Carbon|null $created_at
 */
class AcceptanceDocument extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'signature_acceptance_id',
        'document_id',
        'document_version_id',
        'organization_id',
        'position',
        'document_sha256',
        'fields_snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'fields_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SignatureAcceptance, $this> */
    public function acceptance(): BelongsTo
    {
        return $this->belongsTo(SignatureAcceptance::class, 'signature_acceptance_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }
}
