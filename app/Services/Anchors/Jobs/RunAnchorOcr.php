<?php

namespace App\Services\Anchors\Jobs;

use App\Models\AnchorScan;
use App\Services\Anchors\AnchorScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * OCR das páginas sem texto (Fase 3 §3.2, classe B), fila própria `ocr.queue` (padrão `ocr`):
 * o OCR é caro em CPU e não pode atrasar a fila das buscas de texto. Uma tentativa só —
 * repetir um OCR que estourou o tempo só gastaria mais CPU; a falha vira
 * `documents.ocr_status = failed` e o preparo manual segue.
 */
class RunAnchorOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $scanId)
    {
        $this->onQueue((string) config('assinavelox.ocr.queue', 'ocr'));

        $pages = max(1, (int) config('assinavelox.ocr.max_pages', 20));
        $perPage = max(1, (int) config('assinavelox.ocr.page_timeout_seconds', 60));
        $this->timeout = min(1500, (int) config('assinavelox.field_anchors.time_budget_seconds', 60) + $pages * $perPage + 90);
    }

    public function handle(AnchorScanner $scanner): void
    {
        $scan = AnchorScan::withoutOrganizationScope()->find($this->scanId);

        if ($scan instanceof AnchorScan) {
            $scanner->runOcr($scan);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(AnchorScanner::class)->markFailed($this->scanId, 'ocr_failed');
    }
}
