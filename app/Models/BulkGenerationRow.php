<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\BulkGeneration\BulkRowOutcome;
use App\Services\BulkGeneration\BulkRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Linha de um lote de geração (Fase 3 §3.1).
 *
 * `payload` é cifrado (`encrypted:array`) e só existe enquanto a linha ainda pode virar
 * envelope; `errors` nunca contém o valor digitado, só a coluna e a mensagem.
 *
 * @property int $id
 * @property int $bulk_generation_id
 * @property int $organization_id
 * @property int $row_index
 * @property array{title?: string, values?: array<string, string>, participants?: array<string, array<string, string>>}|null $payload
 * @property BulkRowStatus $status
 * @property list<array{column: int|null, header: string|null, message: string}>|null $errors
 * @property int|null $envelope_id
 * @property BulkRowOutcome|null $outcome
 * @property string|null $outcome_message
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BulkGeneration $bulkGeneration
 * @property-read Envelope|null $envelope
 */
class BulkGenerationRow extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = [
        'bulk_generation_id',
        'organization_id',
        'row_index',
        'payload',
        'status',
        'errors',
        'envelope_id',
        'outcome',
        'outcome_message',
        'error',
        'attempts',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_index' => 'integer',
            'payload' => 'encrypted:array',
            'status' => BulkRowStatus::class,
            'errors' => 'array',
            'outcome' => BulkRowOutcome::class,
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BulkGeneration, $this> */
    public function bulkGeneration(): BelongsTo
    {
        return $this->belongsTo(BulkGeneration::class);
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
