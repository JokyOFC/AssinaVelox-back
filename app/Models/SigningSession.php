<?php

namespace App\Models;

use App\Enums\SigningSessionStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\SigningSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $recipient_id
 * @property int $envelope_id
 * @property int $document_version_id
 * @property int|null $access_link_id
 * @property int $organization_id
 * @property string $token_digest
 * @property SigningSessionStatus $status
 * @property string|null $authorization_token_digest
 * @property Carbon|null $authorization_expires_at
 * @property string|null $snapshot_hash
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $expires_at
 * @property Carbon|null $authenticated_at
 * @property Carbon|null $document_presented_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 */
class SigningSession extends Model
{
    /** @use HasFactory<SigningSessionFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'recipient_id',
        'envelope_id',
        'document_version_id',
        'access_link_id',
        'organization_id',
        'token_digest',
        'status',
        'authorization_token_digest',
        'authorization_expires_at',
        'snapshot_hash',
        'ip_address',
        'user_agent',
        'expires_at',
        'authenticated_at',
        'document_presented_at',
        'consumed_at',
        'last_seen_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => SigningSessionStatus::PendingAuth->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SigningSessionStatus::class,
            'authorization_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'authenticated_at' => 'datetime',
            'document_presented_at' => 'datetime',
            'consumed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    /** @return BelongsTo<RecipientAccessLink, $this> */
    public function accessLink(): BelongsTo
    {
        return $this->belongsTo(RecipientAccessLink::class, 'access_link_id');
    }

    /** @return HasMany<AuthChallenge, $this> */
    public function authChallenges(): HasMany
    {
        return $this->hasMany(AuthChallenge::class);
    }

    /** @return HasOne<SignatureAcceptance, $this> */
    public function acceptance(): HasOne
    {
        return $this->hasOne(SignatureAcceptance::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAuthenticated(): bool
    {
        return $this->status === SigningSessionStatus::Authenticated && ! $this->isExpired();
    }

    public function hasValidAuthorization(): bool
    {
        return $this->authorization_token_digest !== null
            && $this->authorization_expires_at !== null
            && $this->authorization_expires_at->isFuture();
    }
}
