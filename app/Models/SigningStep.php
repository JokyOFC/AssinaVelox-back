<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Etapa do fluxo condicional (Fase 3 §3.3, F-FLOW — docs/fase-3/etapas-e-delegacao.md §2).
 *
 * Os participantes da etapa são os `recipients` com `signing_step_index = step_index`. A
 * condição é o JSON do esquema FECHADO de App\Services\Envelopes\Steps\StepCondition; nada
 * aqui é avaliado como código.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $step_index
 * @property string|null $name
 * @property array<string, mixed>|null $condition
 * @property string $status
 * @property Carbon|null $evaluated_at
 * @property array<string, mixed>|null $evaluation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SigningStep extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** Ainda não alcançada. */
    public const STATUS_PENDING = 'pending';

    /** Alcançada: sem condição ou com a condição verdadeira no momento da avaliação. */
    public const STATUS_ACTIVE = 'active';

    /** Condição falsa: participantes cancelados com motivo, nunca notificados. */
    public const STATUS_SKIPPED = 'skipped';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'step_index',
        'name',
        'condition',
        'status',
        'evaluated_at',
        'evaluation',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_index' => 'integer',
            'condition' => 'array',
            'evaluation' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Alcançada',
            self::STATUS_SKIPPED => 'Não aplicável (pulada)',
            default => 'Aguardando',
        };
    }
}
