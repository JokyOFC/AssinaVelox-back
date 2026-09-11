<?php

namespace App\Models;

use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentSourceType;
use App\Enums\DocumentVersionKind;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $envelope_id
 * @property int $organization_id
 * @property string $name
 * @property string $original_filename
 * @property DocumentSourceType $source_type
 * @property DocumentProcessingStatus $processing_status
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int|null $current_version_id
 * @property int $position
 * @property int|null $sent_version_id
 * @property int|null $final_version_id
 * @property int|null $page_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'envelope_id',
        'organization_id',
        'name',
        'original_filename',
        'source_type',
        'processing_status',
        'failure_code',
        'failure_message',
        'current_version_id',
        'page_count',
        // Fase 2 §2.3 (docs/fase-2/multi-documento-e-papeis.md).
        'position',
        'sent_version_id',
        'final_version_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'processing_status' => DocumentProcessingStatus::Uploaded->value,
        'position' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => DocumentSourceType::class,
            'processing_status' => DocumentProcessingStatus::class,
            'page_count' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * Versão congelada no envio para ESTE documento (Fase 2 §2.3).
     *
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function sentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'sent_version_id');
    }

    /**
     * Versão final deste documento, gravada pela finalização.
     *
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function finalVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'final_version_id');
    }

    /** @return HasMany<AcceptanceDocument, $this> */
    public function acceptanceDocuments(): HasMany
    {
        return $this->hasMany(AcceptanceDocument::class);
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version_number');
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /** @return HasOne<DocumentVersion, $this> */
    public function originalVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)
            ->where('kind', DocumentVersionKind::Original->value)
            ->oldestOfMany('version_number');
    }

    /** @return HasOne<DocumentVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->latestOfMany('version_number');
    }

    public function isReady(): bool
    {
        return $this->processing_status === DocumentProcessingStatus::Ready;
    }

    public function nextVersionNumber(): int
    {
        return ((int) $this->versions()->max('version_number')) + 1;
    }
}
