<?php

namespace App\Services\Anchors\Jobs;

use App\Models\AnchorScan;
use App\Services\Anchors\AnchorScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

/**
 * Busca de âncoras no texto do PDF (Fase 3 §3.2), fila `field_anchors.queue` (padrão
 * `anchors`). Só o id da busca viaja na fila — nada de texto, caminho ou segredo (T10).
 *
 * Documento de modelo HTML/DOCX ainda convertendo: o job se reagenda a cada
 * `field_anchors.wait_seconds` por até `field_anchors.wait_attempts` vezes; esgotado, a busca
 * termina `failed` (`document_not_ready`) e o preparo manual segue.
 */
class DetectFieldAnchors implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly int $scanId)
    {
        $this->onQueue((string) config('assinavelox.field_anchors.queue', 'anchors'));
        $this->tries = max(1, (int) config('assinavelox.field_anchors.wait_attempts', 40)) + 1;
        $this->timeout = max(30, (int) config('assinavelox.field_anchors.process_timeout_seconds', 90) + 60);
    }

    public function handle(AnchorScanner $scanner): void
    {
        $scan = AnchorScan::withoutOrganizationScope()->find($this->scanId);

        if (! $scan instanceof AnchorScan) {
            return;
        }

        if (! $scanner->run($scan)) {
            $this->release(max(1, (int) config('assinavelox.field_anchors.wait_seconds', 15)));
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(AnchorScanner::class)->markFailed(
            $this->scanId,
            $exception instanceof MaxAttemptsExceededException ? 'document_not_ready' : 'unknown_error',
        );
    }
}
