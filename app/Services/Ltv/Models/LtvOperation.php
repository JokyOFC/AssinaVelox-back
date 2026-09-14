<?php

namespace App\Services\Ltv\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Operação de longo prazo (P3-LTV): assinatura B-T/B-LT/B-LTA ou re-carimbo de arquivamento.
 *
 * @property int $id
 * @property string $ulid
 * @property string $kind
 * @property string $idempotency_key
 * @property int|null $organization_id
 * @property int|null $envelope_id
 * @property int|null $verification_record_id
 * @property int|null $document_id
 * @property int|null $source_document_version_id
 * @property int|null $result_document_version_id
 * @property string|null $source_sha256
 * @property string|null $result_sha256
 * @property string $status
 * @property string|null $requested_level
 * @property string|null $effective_level
 * @property list<array<string, string>>|null $degradations
 * @property array<string, list<string>>|null $serials
 * @property string|null $error_code
 * @property int $attempts
 * @property string|null $correlation_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LtvOperation extends Model
{
    public const KIND_SIGN = 'sign';

    public const KIND_REFRESH = 'refresh';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_FAILED = 'failed';

    protected $table = 'ltv_operations';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'degradations' => 'array',
            'serials' => 'array',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_DEGRADED], true);
    }
}
