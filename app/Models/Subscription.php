<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma assinatura vigente por organização (garantido por transação + lock no serviço).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $canceled_at
 * @property bool $cancel_at_period_end
 * @property int $envelopes_used
 * @property int $envelopes_reserved
 * @property string|null $provider
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Plan $plan
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'plan_id',
        'status',
        'started_at',
        'current_period_start',
        'current_period_end',
        'canceled_at',
        'cancel_at_period_end',
        'envelopes_used',
        'envelopes_reserved',
        'provider',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => SubscriptionStatus::Pending->value,
        'cancel_at_period_end' => false,
        'envelopes_used' => 0,
        'envelopes_reserved' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'canceled_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'envelopes_used' => 'integer',
            'envelopes_reserved' => 'integer',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<PlanConsumption, $this> */
    public function consumptions(): HasMany
    {
        return $this->hasMany(PlanConsumption::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function allowsSending(): bool
    {
        return $this->status->allowsSending();
    }

    /**
     * Envelopes ainda disponíveis no período (null = ilimitado).
     */
    public function remainingEnvelopes(): ?int
    {
        $quota = $this->plan->envelope_quota;

        if ($quota === null) {
            return null;
        }

        return max(0, $quota - $this->envelopes_used - $this->envelopes_reserved);
    }

    public function hasEnvelopeQuotaAvailable(int $quantity = 1): bool
    {
        $remaining = $this->remainingEnvelopes();

        return $remaining === null || $remaining >= $quantity;
    }
}
