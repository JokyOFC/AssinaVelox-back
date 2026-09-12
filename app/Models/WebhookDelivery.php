<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Webhooks\WebhookEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entrega de um evento a um endpoint (docs/fase-2/webhooks.md §3 e §5).
 *
 * `ulid` é o id da ENTREGA (X-AssinaVelox-Delivery-Id): o mesmo em todas as tentativas e no
 * reenvio manual. `payload` é o corpo bruto assinado. `history` guarda as tentativas, sem
 * cabeçalho, segredo ou assinatura.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $webhook_endpoint_id
 * @property int|null $envelope_id
 * @property string $event_id
 * @property string $event_type
 * @property string $payload
 * @property string $status
 * @property bool $is_test
 * @property int $attempts
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $locked_until
 * @property int|null $last_response_code
 * @property int|null $last_duration_ms
 * @property string|null $last_error
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $delivered_at
 * @property list<array<string, mixed>>|null $history
 * @property string|null $correlation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookDelivery extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** Na fila, ainda sem tentativa (ou reenvio manual pedido). */
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    /** Última tentativa falhou; há retentativa agendada em `next_retry_at`. */
    public const STATUS_FAILED = 'failed';

    /** Tentativas esgotadas; só o reenvio manual tenta de novo. */
    public const STATUS_EXHAUSTED = 'exhausted';

    /** Parou sem tentar: endpoint pausado/removido ou flag desligada. */
    public const STATUS_CANCELED = 'canceled';

    /** @var list<string> */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_FAILED];

    /** @var list<string> */
    public const TERMINAL_STATUSES = [self::STATUS_DELIVERED, self::STATUS_EXHAUSTED, self::STATUS_CANCELED];

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DELIVERED,
        self::STATUS_FAILED,
        self::STATUS_EXHAUSTED,
        self::STATUS_CANCELED,
    ];

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'webhook_endpoint_id',
        'envelope_id',
        'event_id',
        'event_type',
        'payload',
        'status',
        'is_test',
        'attempts',
        'next_retry_at',
        'locked_until',
        'last_response_code',
        'last_duration_ms',
        'last_error',
        'last_attempt_at',
        'delivered_at',
        'history',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_response_code' => 'integer',
            'last_duration_ms' => 'integer',
            'last_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'history' => 'array',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function eventType(): ?WebhookEventType
    {
        return WebhookEventType::tryFrom($this->event_type);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Na fila',
            self::STATUS_DELIVERED => 'Entregue',
            self::STATUS_FAILED => 'Falhou — nova tentativa agendada',
            self::STATUS_EXHAUSTED => 'Falhou — tentativas esgotadas',
            self::STATUS_CANCELED => 'Cancelada',
            default => $status,
        };
    }
}
