<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pedido de delegação (Fase 3 §3.3, F-FLOW — docs/fase-3/etapas-e-delegacao.md §3).
 *
 * O delegado é um NOVO participante (`to_recipient_id`) com convite, código e aceite próprios.
 * O aceite dele é DELE — nunca "em nome de" quem delegou. `ip_address` já chega truncado.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $from_recipient_id
 * @property int|null $to_recipient_id
 * @property string $to_name
 * @property string $to_email
 * @property string $reason
 * @property string $status
 * @property int $chain_depth
 * @property Carbon $requested_at
 * @property Carbon|null $delegated_at
 * @property Carbon|null $approved_by_sender_at
 * @property int|null $approved_by_user_id
 * @property Carbon|null $rejected_at
 * @property int|null $rejected_by_user_id
 * @property string|null $decision_note
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Delegation extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EFFECTIVE = 'effective';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_VOID = 'void';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'from_recipient_id',
        'to_recipient_id',
        'to_name',
        'to_email',
        'reason',
        'status',
        'chain_depth',
        'requested_at',
        'delegated_at',
        'approved_by_sender_at',
        'approved_by_user_id',
        'rejected_at',
        'rejected_by_user_id',
        'decision_note',
        'ip_address',
        'user_agent',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'chain_depth' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chain_depth' => 'integer',
            'requested_at' => 'datetime',
            'delegated_at' => 'datetime',
            'approved_by_sender_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<Recipient, $this> */
    public function fromRecipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class, 'from_recipient_id');
    }

    /** @return BelongsTo<Recipient, $this> */
    public function toRecipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class, 'to_recipient_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_EFFECTIVE => 'Delegação em vigor',
            self::STATUS_REJECTED => 'Recusada por quem enviou',
            self::STATUS_VOID => 'Sem efeito',
            default => 'Aguardando confirmação de quem enviou',
        };
    }
}
