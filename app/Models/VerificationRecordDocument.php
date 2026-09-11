<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Resumos publicados de UM documento do envelope (Fase 2 §2.3).
 *
 * Sem escopo de organização, como o registro-pai: a página `/verificar` é pública e chega
 * aqui pelo código de verificação do envelope.
 *
 * @property int $id
 * @property int $verification_record_id
 * @property int|null $document_id
 * @property int $position
 * @property string $name
 * @property string|null $original_sha256
 * @property string|null $sent_sha256
 * @property string|null $consolidated_sha256
 * @property string $final_sha256
 * @property int|null $final_document_version_id
 * @property int|null $page_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class VerificationRecordDocument extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'verification_record_id',
        'document_id',
        'position',
        'name',
        'original_sha256',
        'sent_sha256',
        'consolidated_sha256',
        'final_sha256',
        'final_document_version_id',
        'page_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'page_count' => 'integer',
        ];
    }

    /** @return BelongsTo<VerificationRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(VerificationRecord::class, 'verification_record_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function finalVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'final_document_version_id');
    }
}
