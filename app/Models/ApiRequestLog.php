<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registro mínimo de uma requisição da API v1 (Fase 2 §2.15, aba "Logs" de Integrações).
 *
 * Só metadados — nunca corpo, cabeçalho, IP, URL com parâmetros ou dado pessoal. Retenção
 * curta: `assinavelox.api.request_logs.retention_days` (MassPrunable + limpeza oportunista em
 * App\Services\Api\ApiRequestRecorder).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $personal_access_token_id
 * @property string $method
 * @property string|null $route
 * @property string|null $path_pattern
 * @property int $status
 * @property int $duration_ms
 * @property string|null $correlation_id
 * @property bool $idempotent_replay
 * @property Carbon $occurred_at
 * @property-read ApiToken|null $token
 */
class ApiRequestLog extends Model
{
    use BelongsToOrganization, HasPublicUlid, MassPrunable;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'personal_access_token_id',
        'method',
        'route',
        'path_pattern',
        'status',
        'duration_ms',
        'correlation_id',
        'idempotent_replay',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'idempotent_replay' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApiToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'personal_access_token_id')->withoutGlobalScopes();
    }

    public static function retentionDays(): int
    {
        return max(1, (int) config('assinavelox.api.request_logs.retention_days', 30));
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::withoutOrganizationScope()
            ->where('occurred_at', '<', Carbon::now()->subDays(self::retentionDays()));
    }
}
