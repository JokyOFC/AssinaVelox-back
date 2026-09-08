<?php

namespace App\Models;

use App\Enums\AccessLinkPurpose;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\RecipientAccessLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Link de convite/download. Só o digest SHA-256 do token é gravado.
 *
 * @property int $id
 * @property string $ulid
 * @property int $recipient_id
 * @property int $envelope_id
 * @property int $document_version_id
 * @property int $organization_id
 * @property string $token_digest
 * @property AccessLinkPurpose $purpose
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property int $use_count
 * @property Carbon|null $created_at
 */
class RecipientAccessLink extends Model
{
    /** @use HasFactory<RecipientAccessLinkFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'recipient_id',
        'envelope_id',
        'document_version_id',
        'organization_id',
        'token_digest',
        'purpose',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'use_count',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'purpose' => AccessLinkPurpose::Signing->value,
        'use_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => AccessLinkPurpose::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'use_count' => 'integer',
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

    /** @return HasMany<SigningSession, $this> */
    public function signingSessions(): HasMany
    {
        return $this->hasMany(SigningSession::class, 'access_link_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Digest gravado para um token bruto (base64url de 32 bytes).
     */
    public static function digestFor(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
