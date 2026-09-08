<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $tax_id
 * @property string $timezone
 * @property string $locale
 * @property array<string, mixed>|null $settings
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $initials
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasPublicUlid, SoftDeletes;

    public const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    public const DEFAULT_LOCALE = 'pt_BR';

    /** @var array<string, mixed> */
    public const DEFAULT_SETTINGS = [
        'default_expiration_days' => 30,
        'otp_required' => true,
        'evidence_show_ip' => 'masked', // masked | full | none
        'refusal_policy' => 'close_envelope',
        'max_resends' => 5,
    ];

    /** @var list<string> */
    protected $fillable = [
        'name',
        'legal_name',
        'tax_id',
        'timezone',
        'locale',
        'settings',
        'created_by_user_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'timezone' => self::DEFAULT_TIMEZONE,
        'locale' => self::DEFAULT_LOCALE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_id' => 'encrypted',
            'settings' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    // -- Relações ---------------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsToMany<User, $this, Membership> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'status'])
            ->withTimestamps();
    }

    /** @return HasMany<MembershipInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(MembershipInvitation::class);
    }

    /** @return HasMany<Folder, $this> */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    /** @return HasMany<Envelope, $this> */
    public function envelopes(): HasMany
    {
        return $this->hasMany(Envelope::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<Recipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class);
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /** @return HasMany<DeliveryAttempt, $this> */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Assinatura vigente (active/trialing/past_due) mais recente.
     *
     * @return HasOne<Subscription, $this>
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->latestOfMany();
    }

    /** @return HasMany<PlanConsumption, $this> */
    public function planConsumptions(): HasMany
    {
        return $this->hasMany(PlanConsumption::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<CertificateReference, $this> */
    public function certificateReferences(): HasMany
    {
        return $this->hasMany(CertificateReference::class);
    }

    /** @return HasMany<VerificationRecord, $this> */
    public function verificationRecords(): HasMany
    {
        return $this->hasMany(VerificationRecord::class);
    }

    // -- Acessores e helpers ----------------------------------------------------------

    /** @return Attribute<string, never> */
    protected function initials(): Attribute
    {
        return Attribute::get(fn (): string => static::initialsFor($this->name));
    }

    public static function initialsFor(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        $first = Str::upper(Str::substr($words[0], 0, 1));

        if (count($words) === 1) {
            return $first.Str::upper(Str::substr($words[0], 1, 1));
        }

        return $first.Str::upper(Str::substr($words[count($words) - 1], 0, 1));
    }

    /**
     * Configuração efetiva (settings da organização sobre os padrões).
     *
     * @return array<string, mixed>
     */
    public function effectiveSettings(): array
    {
        return array_replace(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->effectiveSettings()[$key] ?? $default;
    }
}
