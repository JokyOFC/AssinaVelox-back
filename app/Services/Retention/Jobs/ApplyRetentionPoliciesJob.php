<?php

namespace App\Services\Retention\Jobs;

use App\Services\Retention\RetentionRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Versão enfileirável do `retention:apply` (para quem prefere rodar a retenção num worker).
 * Única na fila (`ShouldBeUnique`): duas execuções nunca se sobrepõem; cada envelope ainda
 * tem o seu próprio lock em EnvelopePurger. O payload leva só o id da organização (ou nada).
 */
final class ApplyRetentionPoliciesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly ?int $organizationId = null) {}

    public function uniqueId(): string
    {
        return 'retention-apply:'.($this->organizationId ?? 'all');
    }

    public function handle(RetentionRunner $runner): void
    {
        $runner->run($this->organizationId);
    }
}
