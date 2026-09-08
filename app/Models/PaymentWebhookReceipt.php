<?php

namespace App\Models;

use App\Enums\WebhookProcessingStatus;
use Database\Factories\PaymentWebhookReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Recibo de webhook de pagamento — idempotência por (provider, event_fingerprint).
 *
 * @property int $id
 * @property string $provider
 * @property string $event_fingerprint
 * @property string|null $topic
 * @property string|null $action
 * @property array<string, mixed>|null $payload
 * @property string|null $signature_header
 * @property bool $signature_valid
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property WebhookProcessingStatus $processing_status
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentWebhookReceipt extends Model
{
    /** @use HasFactory<PaymentWebhookReceiptFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'event_fingerprint',
        'topic',
        'action',
        'payload',
        'signature_header',
        'signature_valid',
        'received_at',
        'processed_at',
        'processing_status',
        'error',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'signature_valid' => false,
        'processing_status' => WebhookProcessingStatus::Received->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'processing_status' => WebhookProcessingStatus::class,
        ];
    }

    /**
     * Fingerprint canônico: type + data.id + action.
     */
    public static function fingerprint(string $type, string|int $dataId, ?string $action): string
    {
        return implode(':', [$type, (string) $dataId, $action ?? '']);
    }
}
