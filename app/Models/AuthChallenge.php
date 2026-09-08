<?php

namespace App\Models;

use App\Enums\DeliveryChannel;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\AuthChallengeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Desafio OTP. O código nunca é gravado — apenas o HMAC-SHA256 sobre "ulid|code".
 *
 * @property int $id
 * @property string $ulid
 * @property int $signing_session_id
 * @property int $recipient_id
 * @property int $envelope_id
 * @property int $organization_id
 * @property DeliveryChannel $channel
 * @property string $code_hash
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property int|null $delivery_attempt_id
 * @property Carbon|null $created_at
 */
class AuthChallenge extends Model
{
    /** @use HasFactory<AuthChallengeFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    public const UPDATED_AT = null;

    public const DEFAULT_MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 10;

    /** @var list<string> */
    protected $fillable = [
        'signing_session_id',
        'recipient_id',
        'envelope_id',
        'organization_id',
        'channel',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
        'delivery_attempt_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'channel' => DeliveryChannel::Email->value,
        'attempts' => 0,
        'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => DeliveryChannel::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SigningSession, $this> */
    public function signingSession(): BelongsTo
    {
        return $this->belongsTo(SigningSession::class);
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

    /** @return BelongsTo<DeliveryAttempt, $this> */
    public function deliveryAttempt(): BelongsTo
    {
        return $this->belongsTo(DeliveryAttempt::class);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < $this->max_attempts;
    }

    public function isVerifiable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired() && $this->hasAttemptsLeft();
    }
}
