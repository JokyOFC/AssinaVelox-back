<?php

namespace App\Console\Commands;

use App\Services\PublicForms\SubmissionPurge;
use Illuminate\Console\Command;

/**
 * Limpeza dos envios de formulário público não confirmados (Fase 2 §2.2,
 * docs/fase-2/formulario-publico.md §5):
 *
 *   php artisan public-forms:purge-submissions
 *
 * Agendado de hora em hora (routes/console.php). Apaga, em lotes, o envio cujo link de
 * confirmação venceu (payload cifrado, IP e navegador). A limpeza oportunista a cada envio
 * continua; este agendamento cobre o formulário que ninguém mais abriu.
 */
class PurgePublicFormSubmissionsCommand extends Command
{
    protected $signature = 'public-forms:purge-submissions {--max-batches=50 : Máximo de lotes por execução}';

    protected $description = 'Apaga os envios de formulário público cuja confirmação venceu.';

    public function handle(SubmissionPurge $purge): int
    {
        $total = 0;
        $batches = max(1, (int) $this->option('max-batches'));

        for ($i = 0; $i < $batches; $i++) {
            $removed = $purge->run();
            $total += $removed;

            if ($removed === 0) {
                break;
            }
        }

        $this->info($total === 0 ? 'Nenhum envio vencido.' : $total.' envio(s) vencido(s) removido(s).');

        return self::SUCCESS;
    }
}
