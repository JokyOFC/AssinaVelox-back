<?php

namespace App\Models;

use App\Enums\AuthMethod;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Exceptions\InvalidRecipientTransition;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\RecipientFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Destinatário/signatário. Não possui conta de usuário.
 *
 * @property int $id
 * @property string $ulid
 * @property int $envelope_id
 * @property int $organization_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property RecipientRole $role
 * @property int $order_index
 * @property RecipientStatus $status
 * @property AuthMethod $auth_method
 * @property Carbon|null $signed_at
 * @property Carbon|null $refused_at
 * @property string|null $refusal_reason
 * @property int $notification_count
 * @property Carbon|null $last_notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $initials
 * @property-read string $masked_email
 */
class Recipient extends Model
{
    /** @use HasFactory<RecipientFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'envelope_id',
        'organization_id',
        'name',
        'email',
        'phone',
        'role',
        'order_index',
        'status',
        'auth_method',
        'signed_at',
        'refused_at',
        'refusal_reason',
        'notification_count',
        'last_notified_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => RecipientRole::Signer->value,
        'status' => RecipientStatus::Pending->value,
        'auth_method' => AuthMethod::EmailOtp->value,
        'order_index' => 1,
        'notification_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => RecipientRole::class,
            'order_index' => 'integer',
            'status' => RecipientStatus::class,
            'auth_method' => AuthMethod::class,
            'signed_at' => 'datetime',
            'refused_at' => 'datetime',
            'notification_count' => 'integer',
            'last_notified_at' => 'datetime',
        ];
    }

    // -- Transições -------------------------------------------------------------------

    /**
     * Valida e define o novo status SEM persistir (quem grava é o serviço).
     *
     * @throws InvalidRecipientTransition
     */
    public function transitionTo(RecipientStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new InvalidRecipientTransition($this->status, $to, $this->ulid);
        }

        $this->status = $to;

        match ($to) {
            RecipientStatus::Signed => $this->signed_at ??= Carbon::now(),
            RecipientStatus::Refused => $this->refused_at ??= Carbon::now(),
            default => null,
        };
    }

    public function canTransitionTo(RecipientStatus $to): bool
    {
        return $this->status->canTransitionTo($to);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    // -- Relações ---------------------------------------------------------------------

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return HasMany<RecipientAccessLink, $this> */
    public function accessLinks(): HasMany
    {
        return $this->hasMany(RecipientAccessLink::class);
    }

    /** @return HasMany<SigningSession, $this> */
    public function signingSessions(): HasMany
    {
        return $this->hasMany(SigningSession::class);
    }

    /** @return HasMany<AuthChallenge, $this> */
    public function authChallenges(): HasMany
    {
        return $this->hasMany(AuthChallenge::class);
    }

    /** @return HasOne<SignatureAcceptance, $this> */
    public function acceptance(): HasOne
    {
        return $this->hasOne(SignatureAcceptance::class);
    }

    /** @return HasMany<SigningField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(SigningField::class)->orderBy('page')->orderBy('sort_order');
    }

    /** @return HasMany<SigningFieldValue, $this> */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(SigningFieldValue::class);
    }

    /** @return HasMany<DeliveryAttempt, $this> */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    // -- Acessores --------------------------------------------------------------------

    /** @return Attribute<string, never> */
    protected function initials(): Attribute
    {
        return Attribute::get(fn (): string => Organization::initialsFor($this->name));
    }

    /**
     * E-mail mascarado para exibição pública (j***@exemplo.com).
     *
     * @return Attribute<string, never>
     */
    protected function maskedEmail(): Attribute
    {
        return Attribute::get(fn (): string => static::maskEmail($this->email));
    }

    public static function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return Str::mask($email, '*', 1);
        }

        [$local, $domain] = explode('@', $email, 2);

        $visible = Str::substr($local, 0, 1);

        return $visible.str_repeat('*', max(3, Str::length($local) - 1)).'@'.$domain;
    }
}
