<?php

namespace App\Jobs\BulkGeneration;

use App\Services\BulkGeneration\RowGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Gera o envelope de uma linha do lote (Fase 3 §3.1). Idempotente por linha
 * ({@see RowGenerator}); único na fila por linha. O payload do job é só o id — nenhum dado
 * pessoal nem segredo vai para a fila (T10).
 */
class GenerateEnvelopeFromRowJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $rowId)
    {
        $this->onQueue((string) config('assinavelox.bulk_generation.queue', config('assinavelox.queues.default', 'default')));
    }

    public function uniqueId(): string
    {
        return 'bulk-row:'.$this->rowId;
    }

    public function uniqueFor(): int
    {
        return 900;
    }

    public function handle(RowGenerator $generator): void
    {
        $generator->handle($this->rowId);
    }

    public function failed(?Throwable $exception): void
    {
        app(RowGenerator::class)->markLost($this->rowId);
    }
}
