<?php

namespace App\Services\Batch\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Código de uso único do lote (`batch_signing_challenges`). Só o HMAC é gravado.
 *
 * @property int $id
 * @property string $ulid
 * @property int $batch_signing_session_id
 * @property int $organization_id
 * @property string $code_hash
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property int|null $delivery_attempt_id
 * @property Carbon|null $created_at
 */
class BatchSigningChallenge extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const UPDATED_AT = null;

    protected $table = 'batch_signing_challenges';

    /** @var list<string> */
    protected $fillable = [
        'batch_signing_session_id',
        'organization_id',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
        'delivery_attempt_id',
    ];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BatchSigningSession, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BatchSigningSession::class, 'batch_signing_session_id')->withoutGlobalScopes();
    }

    public function isVerifiable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < $this->max_attempts;
    }
}
