<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Execução do `retention:apply` (Fase 2 §2.19). Só contagens no resumo.
 *
 * @property int $id
 * @property string $ulid
 * @property bool $dry_run
 * @property string $status
 * @property array<string, mixed>|null $summary
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
class RetentionRun extends Model
{
    use HasPublicUlid;

    /** @var list<string> */
    protected $fillable = ['dry_run', 'status', 'summary', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
