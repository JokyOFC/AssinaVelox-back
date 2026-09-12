<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pedido de estorno (Fase 2, onda D — roadmap §2.20 `payment_refunds`).
 *
 * A `idempotency_key` é gravada ANTES da chamada ao provedor e reusada como `X-Idempotency-Key`
 * em qualquer repetição: repetir o mesmo pedido nunca cria um segundo estorno. `unknown` é o
 * estado legítimo de um timeout (T5): antes de repetir, consulta-se a lista de estornos do
 * pagamento no provedor.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $payment_id
 * @property string $provider
 * @property string|null $provider_refund_id
 * @property int $amount_cents
 * @property string $currency
 * @property string $kind
 * @property string $status
 * @property string|null $provider_status
 * @property string $reason
 * @property string $initiator
 * @property int|null $requested_by_user_id
 * @property string $idempotency_key
 * @property string|null $correlation_id
 * @property string|null $error
 * @property Carbon $requested_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Payment $payment
 * @property-read User|null $requestedBy
 */
class PaymentRefund extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const KIND_TOTAL = 'total';

    public const KIND_PARTIAL = 'partial';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_FAILED = 'failed';

    public const INITIATOR_PLATFORM_ADMIN = 'platform_admin';

    public const INITIATOR_OWNER = 'owner';

    /** Estados em que o pedido ainda pode virar estorno (bloqueiam um segundo pedido). */
    public const OPEN_STATUSES = [self::STATUS_REQUESTED, self::STATUS_PENDING, self::STATUS_UNKNOWN];

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'payment_id',
        'provider',
        'provider_refund_id',
        'amount_cents',
        'currency',
        'kind',
        'status',
        'provider_status',
        'reason',
        'initiator',
        'requested_by_user_id',
        'idempotency_key',
        'correlation_id',
        'error',
        'requested_at',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Mensagem de retorno para quem pediu o estorno.
     *
     * @return array{0: 'success'|'warning'|'error', 1: string}
     */
    public function outcomeMessage(): array
    {
        return match ($this->status) {
            self::STATUS_APPROVED => ['success', $this->kind === self::KIND_TOTAL
                ? 'Estorno integral confirmado pelo Mercado Pago. O plano não será renovado: vale até o fim do ciclo atual e depois a conta volta ao plano Grátis. Documentos já assinados continuam válidos.'
                : 'Estorno parcial confirmado pelo Mercado Pago. O plano e a cota do ciclo não mudam.'],
            self::STATUS_PENDING, self::STATUS_REQUESTED => ['warning', 'Estorno solicitado. O Mercado Pago ainda está processando; o status é atualizado quando ele confirmar.'],
            self::STATUS_UNKNOWN => ['warning', 'Não conseguimos confirmar o estorno com o Mercado Pago. Nada será repetido às cegas: o provedor será consultado antes de qualquer nova tentativa.'],
            default => ['error', 'O Mercado Pago recusou o estorno. Nenhum valor foi devolvido.'],
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_REQUESTED => 'Solicitado',
            self::STATUS_PENDING => 'Em processamento no Mercado Pago',
            self::STATUS_APPROVED => 'Estornado',
            self::STATUS_REJECTED => 'Recusado pelo Mercado Pago',
            self::STATUS_CANCELLED => 'Cancelado',
            self::STATUS_UNKNOWN => 'Sem confirmação — consultando o provedor',
            self::STATUS_FAILED => 'Não realizado',
            default => $this->status,
        };
    }
}
