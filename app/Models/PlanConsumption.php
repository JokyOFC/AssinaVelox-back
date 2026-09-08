<?php

namespace App\Models;

use App\Enums\PlanConsumptionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PlanConsumptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ledger de consumo do plano (reserva → commit | release), idempotente por chave.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $subscription_id
 * @property int|null $envelope_id
 * @property string $idempotency_key
 * @property int $quantity
 * @property PlanConsumptionStatus $status
 * @property Carbon|null $reserved_at
 * @property Carbon|null $committed_at
 * @property Carbon|null $released_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlanConsumption extends Model
{
    /** @use HasFactory<PlanConsumptionFactory> */
    use BelongsToOrganization, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'subscription_id',
        'envelope_id',
        'idempotency_key',
        'quantity',
        'status',
        'reserved_at',
        'committed_at',
        'released_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'quantity' => 1,
        'status' => PlanConsumptionStatus::Reserved->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => PlanConsumptionStatus::class,
            'reserved_at' => 'datetime',
            'committed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /**
     * Chave de idempotência padrão para o envio de um envelope.
     */
    public static function sendKeyFor(Envelope|int $envelope): string
    {
        $id = $envelope instanceof Envelope ? $envelope->getKey() : $envelope;

        return "envelope:{$id}:send";
    }
}
