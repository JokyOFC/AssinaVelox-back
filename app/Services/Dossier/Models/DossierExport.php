<?php

namespace App\Services\Dossier\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Envelope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pedido de dossiê ZIP (tabela `dossier_exports`, roadmap §2.13 / Q12 / Q23).
 *
 * O binding de rota é escopado pela organização corrente (BelongsToOrganization): o pedido
 * de outra organização responde 404. Mora em App\Services\Dossier\Models porque `app/Models`
 * está fora da área do K-TSA.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $requested_by_user_id
 * @property string $kind
 * @property int|null $envelope_id
 * @property list<int>|null $envelope_ids
 * @property string $idempotency_key
 * @property string $status
 * @property int $envelope_count
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string|null $sha256
 * @property int|null $size_bytes
 * @property string|null $manifest_sha256
 * @property string|null $timestamp_status
 * @property int|null $timestamp_token_id
 * @property string|null $error_code
 * @property int $attempts
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $purged_at
 * @property int $download_count
 * @property Carbon|null $last_downloaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DossierExport extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const KIND_SINGLE = 'single';

    public const KIND_BULK = 'bulk';

    public const STATUS_PENDING = 'pending';

    public const STATUS_BUILDING = 'building';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'dossier_exports';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'requested_by_user_id',
        'kind',
        'envelope_id',
        'envelope_ids',
        'idempotency_key',
        'status',
        'envelope_count',
        'storage_disk',
        'storage_path',
        'sha256',
        'size_bytes',
        'manifest_sha256',
        'timestamp_status',
        'timestamp_token_id',
        'error_code',
        'attempts',
        'completed_at',
        'expires_at',
        'purged_at',
        'download_count',
        'last_downloaded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'envelope_ids' => 'array',
            'envelope_count' => 'integer',
            'size_bytes' => 'integer',
            'attempts' => 'integer',
            'download_count' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'purged_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->storage_path !== null && $this->purged_at === null;
    }

    public function isExpired(?Carbon $now = null): bool
    {
        if ($this->status === self::STATUS_EXPIRED || $this->purged_at !== null) {
            return true;
        }

        return $this->expires_at !== null && $this->expires_at->lte($now ?? Carbon::now());
    }

    /**
     * Ids dos envelopes contidos (single: o próprio; bulk: a lista pedida).
     *
     * @return list<int>
     */
    public function envelopeIds(): array
    {
        if ($this->kind === self::KIND_SINGLE) {
            return $this->envelope_id === null ? [] : [(int) $this->envelope_id];
        }

        return array_map('intval', $this->envelope_ids ?? []);
    }
}
