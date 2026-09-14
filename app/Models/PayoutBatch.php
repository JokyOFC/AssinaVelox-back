<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lote de repasse (Fase 3 §3.10). O sistema calcula, NÃO paga: `draft` → `paid` só por ação
 * manual da operadora (quem, quando, referência externa) ou `draft` → `canceled` (libera os
 * lançamentos para o próximo lote).
 *
 * @property int $id
 * @property string $ulid
 * @property string $currency
 * @property string $status
 * @property Carbon $cutoff_at
 * @property int $total_cents
 * @property int $affiliates_count
 * @property int $entries_count
 * @property int|null $created_by_user_id
 * @property int|null $paid_by_user_id
 * @property Carbon|null $paid_at
 * @property Carbon|null $marked_paid_at
 * @property string|null $external_reference
 * @property string|null $notes
 * @property int|null $canceled_by_user_id
 * @property Carbon|null $canceled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $createdBy
 * @property-read User|null $paidBy
 * @property-read User|null $canceledBy
 */
class PayoutBatch extends Model
{
    use HasPublicUlid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELED = 'canceled';

    /** @var list<string> */
    protected $fillable = [
        'currency',
        'status',
        'cutoff_at',
        'total_cents',
        'affiliates_count',
        'entries_count',
        'created_by_user_id',
        'paid_by_user_id',
        'paid_at',
        'marked_paid_at',
        'external_reference',
        'notes',
        'canceled_by_user_id',
        'canceled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cutoff_at' => 'datetime',
            'total_cents' => 'integer',
            'affiliates_count' => 'integer',
            'entries_count' => 'integer',
            'paid_at' => 'datetime',
            'marked_paid_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by_user_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'Aberto (aguardando repasse)',
            self::STATUS_PAID => 'Pago (registrado manualmente)',
            self::STATUS_CANCELED => 'Cancelado',
            default => $status,
        };
    }
}
