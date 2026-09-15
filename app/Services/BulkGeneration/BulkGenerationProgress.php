<?php

namespace App\Services\BulkGeneration;

use App\Models\BulkGeneration;
use App\Models\BulkGenerationRow;
use Illuminate\Support\Carbon;

/**
 * Contagens de acompanhamento e encerramento do lote.
 */
final class BulkGenerationProgress
{
    public function __construct(private readonly BulkGenerationStorage $storage) {}

    /**
     * Encerra o lote em `completed` quando nenhuma linha está por gerar ou com envio pendente.
     */
    public function finishIfDone(int $batchId): bool
    {
        $open = BulkGenerationRow::withoutOrganizationScope()
            ->where('bulk_generation_id', $batchId)
            ->where(function ($query): void {
                $query->whereIn('status', BulkRowStatus::inFlightValues())
                    ->orWhere(fn ($created) => $created->where('status', BulkRowStatus::Created->value)->whereNull('outcome'));
            })
            ->exists();

        if ($open) {
            return false;
        }

        $updated = BulkGeneration::withoutOrganizationScope()
            ->whereKey($batchId)
            ->where('status', BulkGenerationStatus::Running->value)
            ->update([
                'status' => BulkGenerationStatus::Completed->value,
                'finished_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        if ($updated === 1) {
            $batch = BulkGeneration::withoutOrganizationScope()->whereKey($batchId)->first();

            if ($batch !== null) {
                $this->storage->delete($batch);
            }
        }

        return $updated === 1;
    }

    /**
     * @return array{rows: int, valid: int, invalid: int, pending: int, created: int, failed: int, canceled: int, sent: int, scheduled: int, ready: int, draft: int, not_sent: int, processed: int}
     */
    public static function counts(BulkGeneration $batch): array
    {
        $byStatus = BulkGenerationRow::withoutOrganizationScope()
            ->where('bulk_generation_id', $batch->getKey())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $byOutcome = BulkGenerationRow::withoutOrganizationScope()
            ->where('bulk_generation_id', $batch->getKey())
            ->whereNotNull('outcome')
            ->selectRaw('outcome, count(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $status = fn (BulkRowStatus $s): int => $byStatus[$s->value] ?? 0;
        $outcome = fn (BulkRowOutcome $o): int => $byOutcome[$o->value] ?? 0;

        $created = $status(BulkRowStatus::Created);
        $failed = $status(BulkRowStatus::Failed);
        $canceled = $status(BulkRowStatus::Canceled);

        return [
            'rows' => (int) $batch->row_count,
            'valid' => (int) $batch->valid_count,
            'invalid' => $status(BulkRowStatus::Invalid),
            'pending' => $status(BulkRowStatus::Pending) + $status(BulkRowStatus::Queued) + $status(BulkRowStatus::Processing),
            'created' => $created,
            'failed' => $failed,
            'canceled' => $canceled,
            'sent' => $outcome(BulkRowOutcome::Sent),
            'scheduled' => $outcome(BulkRowOutcome::Scheduled),
            'ready' => $outcome(BulkRowOutcome::Ready),
            'draft' => $outcome(BulkRowOutcome::Draft),
            'not_sent' => $outcome(BulkRowOutcome::NotSent),
            'processed' => $created + $failed + $canceled,
        ];
    }
}
