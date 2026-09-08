<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\MembershipInvitationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Não usa o escopo de organização: o convite é aceito por e-mail, fora do contexto de
 * uma organização corrente.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $email
 * @property MembershipRole $role
 * @property string $token_digest
 * @property int|null $invited_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read InvitationStatus $status
 * @property-read Organization $organization
 */
class MembershipInvitation extends Model
{
    /** @use HasFactory<MembershipInvitationFactory> */
    use HasFactory, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'email',
        'role',
        'token_digest',
        'invited_by_user_id',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return Attribute<InvitationStatus, never> */
    protected function status(): Attribute
    {
        return Attribute::get(fn (): InvitationStatus => $this->resolveStatus());
    }

    public function resolveStatus(): InvitationStatus
    {
        if ($this->accepted_at !== null) {
            return InvitationStatus::Accepted;
        }

        if ($this->revoked_at !== null) {
            return InvitationStatus::Revoked;
        }

        if ($this->expires_at->isPast()) {
            return InvitationStatus::Expired;
        }

        return InvitationStatus::Pending;
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::Pending;
    }
}
