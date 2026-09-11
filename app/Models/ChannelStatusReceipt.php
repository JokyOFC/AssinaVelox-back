<?php

namespace App\Models;

use App\Enums\DeliveryChannel;
use App\Enums\WebhookProcessingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recibo de aviso de status de SMS/WhatsApp com assinatura válida (Fase 2 §2.9).
 * Idempotência por UNIQUE(provider, event_fingerprint).
 *
 * @property int $id
 * @property DeliveryChannel $channel
 * @property string $provider
 * @property string $event_fingerprint
 * @property string|null $provider_message_id
 * @property string|null $reported_status
 * @property int|null $delivery_attempt_id
 * @property array<string, mixed>|null $payload
 * @property bool $signature_valid
 * @property bool $is_simulated
 * @property Carbon|null $occurred_at
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property WebhookProcessingStatus $processing_status
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChannelStatusReceipt extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'channel',
        'provider',
        'event_fingerprint',
        'provider_message_id',
        'reported_status',
        'delivery_attempt_id',
        'payload',
        'signature_valid',
        'is_simulated',
        'occurred_at',
        'received_at',
        'processed_at',
        'processing_status',
        'error',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'signature_valid' => false,
        'is_simulated' => false,
        'processing_status' => WebhookProcessingStatus::Received->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => DeliveryChannel::class,
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'is_simulated' => 'boolean',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'processing_status' => WebhookProcessingStatus::class,
        ];
    }

    /** @return BelongsTo<DeliveryAttempt, $this> */
    public function deliveryAttempt(): BelongsTo
    {
        return $this->belongsTo(DeliveryAttempt::class);
    }
}
