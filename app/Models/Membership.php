<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Não usa o escopo de organização: o seletor de organizações lista memberships do usuário
 * em todas as organizações.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property MembershipRole $role
 * @property MembershipStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User $user
 */
class Membership extends Pivot
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $table = 'memberships';

    public $incrementing = true;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'user_id', 'role', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => MembershipStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => MembershipStatus::class,
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function isOwner(): bool
    {
        return $this->role === MembershipRole::Owner;
    }
}
