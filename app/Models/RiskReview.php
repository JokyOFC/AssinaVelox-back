<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Services\Risk\RiskDecision;
use App\Services\Risk\RiskReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Caso da fila de revisão humana do antifraude (Fase 3 §3.7).
 *
 * Os sinais do caso são os da organização com id em (`after_signal_id`, `through_signal_id`];
 * enquanto aberto, `through_signal_id` é nulo e todo sinal novo entra no caso. A decisão fixa
 * o intervalo. Sem escopo global de organização (lido pelo painel interno).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property RiskReviewStatus $status
 * @property string $trigger
 * @property int $after_signal_id
 * @property int|null $through_signal_id
 * @property string|null $status_before
 * @property Carbon $opened_at
 * @property Carbon|null $restricted_at
 * @property Carbon|null $appeal_requested_at
 * @property int|null $appeal_requested_by_user_id
 * @property string|null $appeal_message
 * @property RiskDecision|null $decision
 * @property string|null $decision_reason
 * @property int|null $reviewer_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User|null $reviewer
 * @property-read User|null $appealRequester
 */
class RiskReview extends Model
{
    use HasPublicUlid;

    public const TRIGGER_SIGNALS = 'signals';

    public const TRIGGER_APPEAL = 'appeal';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'status',
        'trigger',
        'after_signal_id',
        'through_signal_id',
        'status_before',
        'opened_at',
        'restricted_at',
        'appeal_requested_at',
        'appeal_requested_by_user_id',
        'appeal_message',
        'decision',
        'decision_reason',
        'reviewer_id',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RiskReviewStatus::class,
            'decision' => RiskDecision::class,
            'after_signal_id' => 'integer',
            'through_signal_id' => 'integer',
            'opened_at' => 'datetime',
            'restricted_at' => 'datetime',
            'appeal_requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === RiskReviewStatus::Open;
    }

    /**
     * Sinais cobertos por este caso.
     *
     * @return Builder<RiskSignal>
     */
    public function signalsQuery(): Builder
    {
        $query = RiskSignal::query()
            ->where('organization_id', $this->organization_id)
            ->where('id', '>', $this->after_signal_id);

        if ($this->through_signal_id !== null) {
            $query->where('id', '<=', $this->through_signal_id);
        }

        return $query;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function appealRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appeal_requested_by_user_id');
    }
}
