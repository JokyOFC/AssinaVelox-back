<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Afiliado do programa (Fase 3 §3.10, docs/fase-3/afiliados.md). Um por usuário.
 *
 * `payout_details` é cifrado em repouso (`encrypted:array`) e fica oculto na serialização:
 * a interface só recebe a versão mascarada (App\Services\Affiliates\PayoutDetails::mask).
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $user_id
 * @property string|null $code
 * @property int $commission_rate_bp
 * @property string $status
 * @property array<string, string>|null $payout_details
 * @property string $terms_version
 * @property Carbon $terms_accepted_at
 * @property string|null $application_ip_hash
 * @property string|null $last_ip_hash
 * @property Carbon|null $last_ip_at
 * @property Carbon|null $approved_at
 * @property int|null $approved_by_user_id
 * @property Carbon|null $rejected_at
 * @property Carbon|null $suspended_at
 * @property string|null $status_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class Affiliate extends Model
{
    use HasPublicUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_SUSPENDED];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'code',
        'commission_rate_bp',
        'status',
        'payout_details',
        'terms_version',
        'terms_accepted_at',
        'application_ip_hash',
        'last_ip_hash',
        'last_ip_at',
        'approved_at',
        'approved_by_user_id',
        'rejected_at',
        'suspended_at',
        'status_reason',
    ];

    /** @var list<string> */
    protected $hidden = ['payout_details', 'application_ip_hash', 'last_ip_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_rate_bp' => 'integer',
            'payout_details' => 'encrypted:array',
            'terms_accepted_at' => 'datetime',
            'last_ip_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Referral, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function hasPayoutDetails(): bool
    {
        return is_array($this->payout_details) && $this->payout_details !== [];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Aguardando aprovação',
            self::STATUS_APPROVED => 'Aprovado',
            self::STATUS_REJECTED => 'Recusado',
            self::STATUS_SUSPENDED => 'Suspenso',
            default => $status,
        };
    }
}
