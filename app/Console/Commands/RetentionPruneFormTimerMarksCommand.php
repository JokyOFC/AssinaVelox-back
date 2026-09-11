<?php

namespace App\Console\Commands;

use App\Services\PublicForms\FillTimer;
use Illuminate\Console\Command;

/**
 * Limpeza das marcas de uso único do carimbo de tempo do formulário público
 * (`public_form_timer_marks`, docs/fase-2/formulario-publico.md §7):
 *
 *   php artisan public-forms:prune-timer-marks
 *
 * Só apaga marcas cujo carimbo já venceu por conta própria — elas não protegem mais nada.
 * De hora em hora (routes/console.php). Idempotente; independe de flag.
 */
class RetentionPruneFormTimerMarksCommand extends Command
{
    protected $signature = 'public-forms:prune-timer-marks';

    protected $description = 'Apaga as marcas vencidas de uso único do carimbo de tempo do formulário público.';

    public function handle(): int
    {
        $this->info(sprintf('Marcas vencidas removidas: %d.', FillTimer::prune()));

        return self::SUCCESS;
    }
}
