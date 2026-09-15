<?php

namespace App\Services\HubSpot\Jobs;

use App\Models\HubSpotActionExecution;
use App\Services\HubSpot\HubSpotObjectSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Grava o estado do envelope no negócio/contato do HubSpot (HubSpotObjectSync). O payload da
 * fila tem SÓ o id da execução e o estado — nunca token (T10).
 */
final class SyncHubSpotObject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public function __construct(public readonly int $executionId, public readonly string $status)
    {
        $this->tries = max(1, (int) config('assinavelox.hubspot.sync_attempts', 5));
        $this->onQueue((string) config('assinavelox.hubspot.queue', 'default'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(HubSpotObjectSync $sync): void
    {
        $sync->push($this->executionId, $this->status);
    }

    public function failed(?Throwable $exception): void
    {
        $execution = HubSpotActionExecution::withoutOrganizationScope()->find($this->executionId);

        if ($execution !== null) {
            app(HubSpotObjectSync::class)->fail($execution, 'retries_exhausted');
        }
    }
}
