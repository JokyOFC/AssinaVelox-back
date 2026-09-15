<?php

namespace App\Jobs\BulkGeneration;

use App\Models\BulkGeneration;
use App\Services\BulkGeneration\BulkGenerationProgress;
use App\Services\BulkGeneration\BulkGenerationPump;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Orquestrador do lote: despachado ao confirmar. Enfileira as linhas pendentes respeitando o
 * limite de concorrência da organização ({@see BulkGenerationPump}); cada linha, ao terminar,
 * puxa a próxima.
 */
class StartBulkGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $batchId)
    {
        $this->onQueue((string) config('assinavelox.bulk_generation.queue', config('assinavelox.queues.default', 'default')));
    }

    public function handle(BulkGenerationPump $pump, BulkGenerationProgress $progress): void
    {
        $batch = BulkGeneration::withoutOrganizationScope()->whereKey($this->batchId)->first();

        if ($batch === null) {
            return;
        }

        $pump->pump((int) $batch->organization_id);
        $progress->finishIfDone((int) $batch->getKey());
    }
}
