<?php

namespace App\Models;

use App\Enums\PlanBillingPeriod;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $price_cents
 * @property string $currency
 * @property PlanBillingPeriod $billing_period
 * @property int|null $envelope_quota
 * @property int|null $user_quota
 * @property array<string, mixed>|null $features
 * @property bool $is_active
 * @property bool $is_public
 * @property bool $is_sandbox
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $formatted_price
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    public const CODE_FREE = 'free';

    public const CODE_PROFESSIONAL = 'professional';

    public const CODE_ENTERPRISE = 'enterprise';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'price_cents',
        'currency',
        'billing_period',
        'envelope_quota',
        'user_quota',
        'features',
        'is_active',
        'is_public',
        'is_sandbox',
        'sort_order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'BRL',
        'billing_period' => PlanBillingPeriod::Monthly->value,
        'price_cents' => 0,
        'is_active' => true,
        'is_public' => true,
        'is_sandbox' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'billing_period' => PlanBillingPeriod::class,
            'envelope_quota' => 'integer',
            'user_quota' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_sandbox' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isFree(): bool
    {
        return $this->price_cents === 0;
    }

    public function hasUnlimitedEnvelopes(): bool
    {
        return $this->envelope_quota === null;
    }

    /** @return Attribute<string, never> */
    protected function formattedPrice(): Attribute
    {
        return Attribute::get(fn (): string => $this->formatPrice());
    }

    public function formatPrice(): string
    {
        if ($this->isFree()) {
            return 'Grátis';
        }

        return Payment::formatBrl($this->price_cents);
    }

    public static function free(): ?self
    {
        return static::query()->where('code', self::CODE_FREE)->first();
    }
}
