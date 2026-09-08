<?php

namespace App\Models;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $envelope_id
 * @property int|null $recipient_id
 * @property DeliveryChannel $channel
 * @property string $provider
 * @property DeliveryPurpose $purpose
 * @property string $to_address
 * @property DeliveryStatus $status
 * @property string|null $provider_message_id
 * @property string|null $error_message
 * @property string|null $correlation_id
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeliveryAttempt extends Model
{
    /** @use HasFactory<DeliveryAttemptFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'recipient_id',
        'channel',
        'provider',
        'purpose',
        'to_address',
        'status',
        'provider_message_id',
        'error_message',
        'correlation_id',
        'queued_at',
        'sent_at',
        'delivered_at',
        'meta',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'channel' => DeliveryChannel::Email->value,
        'status' => DeliveryStatus::Queued->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => DeliveryChannel::class,
            'purpose' => DeliveryPurpose::class,
            'status' => DeliveryStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /** @return HasMany<AuthChallenge, $this> */
    public function authChallenges(): HasMany
    {
        return $this->hasMany(AuthChallenge::class);
    }
}
