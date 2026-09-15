<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\CloudImport\CloudProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Importação de um arquivo do Google Drive ou do Dropbox (Fase 3 §3.9, G-CONN,
 * docs/fase-3/conectores.md §3). Guarda a ORIGEM (provedor, id externo), o hash e quem
 * importou — nunca token, link de download ou conteúdo.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $envelope_id
 * @property int|null $document_id
 * @property CloudProvider $provider
 * @property string|null $external_id
 * @property string|null $original_filename
 * @property string|null $sha256
 * @property int|null $size_bytes
 * @property string $status
 * @property string|null $rejection_code
 * @property bool $simulated
 * @property int|null $imported_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CloudImport extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'document_id',
        'provider',
        'external_id',
        'original_filename',
        'sha256',
        'size_bytes',
        'status',
        'rejection_code',
        'simulated',
        'imported_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => CloudProvider::class,
            'size_bytes' => 'integer',
            'simulated' => 'boolean',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }
}
