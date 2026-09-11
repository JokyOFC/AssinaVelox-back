<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sessão de "acessar como" (roadmap §2.14 / Q15). Não usa o escopo de organização: é um
 * registro da PLATAFORMA sobre uma organização (o painel interno não tem organização
 * corrente).
 *
 * @property int $id
 * @property string $ulid
 * @property int $admin_user_id
 * @property int $organization_id
 * @property int $target_user_id
 * @property string $reason
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property int $pages_viewed
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $admin
 * @property-read User $targetUser
 * @property-read Organization $organization
 */
class Impersonation extends Model
{
    use HasPublicUlid;

    public const END_STOPPED = 'stopped';

    public const END_EXPIRED = 'expired';

    public const END_LOGOUT = 'logout';

    public const END_INVALID = 'invalid';

    /** @var list<string> */
    protected $fillable = [
        'admin_user_id',
        'organization_id',
        'target_user_id',
        'reason',
        'started_at',
        'expires_at',
        'ended_at',
        'end_reason',
        'pages_viewed',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'pages_viewed' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<Impersonation>  $query
     * @return Builder<Impersonation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', Carbon::now());
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
