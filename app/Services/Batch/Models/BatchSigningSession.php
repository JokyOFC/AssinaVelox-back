<?php

namespace App\Services\Batch\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Organization;
use App\Models\Recipient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Link de assinatura em lote (`batch_signing_sessions`, docs/fase-2/presencial-e-lote.md §3).
 *
 * Mora em `App\Services\Batch\Models` porque `app/Models` está fora da área C-PRES.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $anchor_recipient_id
 * @property string $email_digest
 * @property string $token_digest
 * @property string|null $session_token_digest
 * @property string $status
 * @property int|null $issued_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $authenticated_at
 * @property Carbon|null $session_expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_seen_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Recipient|null $anchor
 * @property-read Organization|null $organization
 */
class BatchSigningSession extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_AUTHENTICATED = 'authenticated';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'batch_signing_sessions';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'anchor_recipient_id',
        'email_digest',
        'token_digest',
        'session_token_digest',
        'status',
        'issued_by_user_id',
        'expires_at',
        'authenticated_at',
        'session_expires_at',
        'revoked_at',
        'last_seen_at',
        'ip_address',
        'user_agent',
    ];

    /** @var list<string> */
    protected $hidden = ['token_digest', 'session_token_digest', 'email_digest'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'authenticated_at' => 'datetime',
            'session_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Recipient, $this> */
    public function anchor(): BelongsTo
    {
        return $this->belongsTo(Recipient::class, 'anchor_recipient_id')->withoutGlobalScopes();
    }

    /** @return HasMany<BatchSigningItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BatchSigningItem::class, 'batch_signing_session_id')->withoutGlobalScopes();
    }

    /** @return HasMany<BatchSigningChallenge, $this> */
    public function challenges(): HasMany
    {
        return $this->hasMany(BatchSigningChallenge::class, 'batch_signing_session_id')->withoutGlobalScopes();
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->status !== self::STATUS_REVOKED
            && $this->expires_at->isFuture();
    }
}
