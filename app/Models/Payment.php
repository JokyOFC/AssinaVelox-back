<?php

namespace App\Models;

use App\Enums\PaymentDisplayStatus;
use App\Enums\PaymentEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $subscription_id
 * @property int $plan_id
 * @property string $provider
 * @property string $external_reference
 * @property string|null $provider_preference_id
 * @property string|null $provider_payment_id
 * @property PaymentStatus $status
 * @property string|null $status_detail
 * @property int $amount_cents
 * @property string $currency
 * @property string|null $payment_method_id
 * @property string|null $payer_email_masked
 * @property string|null $checkout_url
 * @property Carbon|null $paid_at
 * @property Carbon|null $activated_at
 * @property PaymentEnvironment $environment
 * @property string|null $payment_type_id
 * @property Carbon|null $expires_at
 * @property int $refunded_cents
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $provider_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PaymentDisplayStatus $display_status
 * @property-read string $formatted_amount
 * @property-read FiscalInvoice|null $fiscalInvoice
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'subscription_id',
        'plan_id',
        'provider',
        'external_reference',
        'provider_preference_id',
        'provider_payment_id',
        'status',
        'status_detail',
        'amount_cents',
        'currency',
        'payment_method_id',
        'payer_email_masked',
        'checkout_url',
        'paid_at',
        'activated_at',
        'environment',
        // Fase 2, onda D (D-PAY) — preenchidas só com a flag `extended_payments`.
        'payment_type_id',
        'expires_at',
        'refunded_cents',
        'cancelled_at',
        'provider_updated_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'provider' => 'mercadopago',
        'status' => PaymentStatus::Pending->value,
        'currency' => 'BRL',
        'environment' => PaymentEnvironment::Sandbox->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'paid_at' => 'datetime',
            'activated_at' => 'datetime',
            'environment' => PaymentEnvironment::class,
            'expires_at' => 'datetime',
            'refunded_cents' => 'integer',
            'cancelled_at' => 'datetime',
            'provider_updated_at' => 'datetime',
        ];
    }

    /** @return HasMany<PaymentRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /** @return HasMany<PaymentChargeback, $this> */
    public function chargebacks(): HasMany
    {
        return $this->hasMany(PaymentChargeback::class);
    }

    /** @return HasOne<FiscalInvoice, $this> */
    public function fiscalInvoice(): HasOne
    {
        return $this->hasOne(FiscalInvoice::class);
    }

    /**
     * Quanto ainda pode ser estornado, em centavos: valor cobrado − o MAIOR entre o total estornado
     * informado pela consulta (`refunded_cents`, espelho de `transaction_amount_refunded`) e a soma
     * dos estornos registrados aqui que já saíram ou podem ter saído (aprovados, em processamento,
     * solicitados ou sem confirmação). Uma consulta que falhou depois de um estorno aprovado não
     * devolve o saldo cheio.
     */
    public function refundableCents(): int
    {
        $committed = (int) PaymentRefund::withoutOrganizationScope()
            ->where('payment_id', $this->getKey())
            ->whereIn('status', [PaymentRefund::STATUS_APPROVED, ...PaymentRefund::OPEN_STATUSES])
            ->sum('amount_cents');

        return max(0, (int) $this->amount_cents - max((int) ($this->refunded_cents ?? 0), $committed));
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            $payment->external_reference ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return Attribute<PaymentDisplayStatus, never> */
    protected function displayStatus(): Attribute
    {
        return Attribute::get(fn (): PaymentDisplayStatus => $this->status->displayStatus());
    }

    /** @return Attribute<string, never> */
    protected function formattedAmount(): Attribute
    {
        return Attribute::get(fn (): string => static::formatBrl($this->amount_cents));
    }

    public static function formatBrl(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    /**
     * O plano já foi aplicado por este pagamento? (aplicação única.)
     */
    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }
}
