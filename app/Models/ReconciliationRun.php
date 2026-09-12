<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Execução da conciliação diária (Fase 2, onda D — roadmap §2.20). Da plataforma: não pertence a
 * uma organização (cruza todas). A conciliação nunca corrige pagamento, só aponta divergência.
 *
 * @property int $id
 * @property string $ulid
 * @property string $provider
 * @property string $environment
 * @property Carbon $window_start
 * @property Carbon $window_end
 * @property string $status
 * @property string $trigger
 * @property int|null $triggered_by_user_id
 * @property int $remote_count
 * @property int $matched_count
 * @property int $divergence_count
 * @property string|null $error
 * @property string|null $correlation_id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReconciliationRun extends Model
{
    use HasPublicUlid;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_ADMIN = 'admin';

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'environment',
        'window_start',
        'window_end',
        'status',
        'trigger',
        'triggered_by_user_id',
        'remote_count',
        'matched_count',
        'divergence_count',
        'error',
        'correlation_id',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'remote_count' => 'integer',
            'matched_count' => 'integer',
            'divergence_count' => 'integer',
        ];
    }

    /** @return HasMany<ReconciliationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_RUNNING => 'Em andamento',
            self::STATUS_COMPLETED => 'Concluída',
            self::STATUS_PARTIAL => 'Parcial (limite de páginas atingido)',
            self::STATUS_FAILED => 'Falhou',
            default => $this->status,
        };
    }
}
