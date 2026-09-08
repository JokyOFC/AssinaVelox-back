<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MembershipRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_platform_admin
 * @property int|null $current_organization_id
 * @property string|null $timezone
 * @property string $locale
 * @property Carbon|null $terms_accepted_at
 * @property string|null $terms_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $initials
 * @property-read Organization|null $currentOrganization
 */
#[Fillable([
    'name',
    'email',
    'password',
    'current_organization_id',
    'timezone',
    'locale',
    'terms_accepted_at',
    'terms_version',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'terms_accepted_at' => 'datetime',
        ];
    }

    // -- Relações ---------------------------------------------------------------------

    /** @return BelongsTo<Organization, $this> */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsToMany<Organization, $this, Membership> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'memberships')
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'status'])
            ->withTimestamps();
    }

    /** @return HasMany<Envelope, $this> */
    public function createdEnvelopes(): HasMany
    {
        return $this->hasMany(Envelope::class, 'created_by_user_id');
    }

    /** @return HasMany<Organization, $this> */
    public function createdOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'created_by_user_id');
    }

    // -- Acessores e helpers ----------------------------------------------------------

    /** @return Attribute<string, never> */
    protected function initials(): Attribute
    {
        return Attribute::get(fn (): string => Organization::initialsFor($this->name));
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function membershipFor(Organization|int $organization): ?Membership
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $this->memberships()->where('organization_id', $id)->first();
    }

    public function roleIn(Organization|int $organization): ?MembershipRole
    {
        return $this->membershipFor($organization)?->role;
    }

    public function belongsToOrganization(Organization|int $organization): bool
    {
        return $this->membershipFor($organization) !== null;
    }
}
