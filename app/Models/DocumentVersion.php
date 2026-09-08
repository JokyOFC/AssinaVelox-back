<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\DocumentVersionKind;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;

/**
 * Imutável após a criação (só created_at). Cada versão referencia bytes exatos no storage.
 *
 * @property int $id
 * @property string $ulid
 * @property int $document_id
 * @property int $organization_id
 * @property int $version_number
 * @property DocumentVersionKind $kind
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $page_count
 * @property array<int, array<string, mixed>>|null $pages_meta
 * @property bool $is_encrypted
 * @property bool $has_signatures
 * @property ActorType|null $created_by_type
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 */
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'organization_id',
        'version_number',
        'kind',
        'storage_disk',
        'storage_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'page_count',
        'pages_meta',
        'is_encrypted',
        'has_signatures',
        'created_by_type',
        'created_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'kind' => DocumentVersionKind::class,
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'pages_meta' => 'array',
            'is_encrypted' => 'boolean',
            'has_signatures' => 'boolean',
            'created_by_type' => ActorType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasOneThrough<Envelope, Document, $this> */
    public function envelope(): HasOneThrough
    {
        return $this->hasOneThrough(
            Envelope::class,
            Document::class,
            'id',           // documents.id
            'id',           // envelopes.id
            'document_id',  // document_versions.document_id
            'envelope_id',  // documents.envelope_id
        );
    }

    /**
     * Largura/altura/rotação/boxes de uma página (1-based), conforme pages_meta.
     *
     * @return array<string, mixed>|null
     */
    public function pageMeta(int $page): ?array
    {
        return $this->pages_meta[$page - 1] ?? null;
    }
}
