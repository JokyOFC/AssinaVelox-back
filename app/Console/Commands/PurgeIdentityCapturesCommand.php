<?php

namespace App\Console\Commands;

use App\Services\Identity\CapturePurge;
use Illuminate\Console\Command;

/**
 * Retenção das fotos da captura simples (Fase 2 §2.10, docs/fase-2/identidade.md §5.5):
 *
 *   php artisan identity:purge-captures
 *
 * Agendado uma vez por dia (routes/console.php). Apaga o ARQUIVO das fotos vinculadas a aceite
 * depois de `capture.retention_days` (a linha fica, com `purged_at`) e a foto inteira que nunca
 * virou aceite depois de `capture.orphan_retention_hours`. Idempotente.
 */
class PurgeIdentityCapturesCommand extends Command
{
    protected $signature = 'identity:purge-captures';

    protected $description = 'Apaga as fotos da captura simples cuja retenção venceu.';

    public function handle(CapturePurge $purge): int
    {
        $result = $purge->run();

        $this->info(sprintf(
            'Fotos com retenção vencida: %d. Fotos sem aceite removidas: %d.',
            $result['expired'],
            $result['orphans'],
        ));

        return self::SUCCESS;
    }
}
