<?php

namespace App\Console\Commands;

use App\Jobs\Envelopes\SendReminderToRecipient;
use App\Services\Envelopes\Reminders\ReminderPlanner;
use Illuminate\Console\Command;

/**
 * Lembretes automáticos (Fase 2 §2.5, flag `features.reminders`):
 *
 *   php artisan envelopes:send-reminders [--limit=500]
 *
 * Agendado de hora em hora (routes/console.php). Seleciona os lembretes devidos e despacha
 * um job por (destinatário, número do lembrete); o job revalida tudo sob lock. Reprocessar é
 * seguro: a chave (destinatário, número) impede lembrete duplicado.
 */
class SendEnvelopeRemindersCommand extends Command
{
    protected $signature = 'envelopes:send-reminders {--limit= : Máximo de lembretes despachados por execução}';

    protected $description = 'Despacha os lembretes automáticos devidos aos signatários pendentes.';

    public function handle(ReminderPlanner $planner): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $due = $planner->due(null, $limit);

        foreach ($due as $candidate) {
            SendReminderToRecipient::dispatch($candidate['recipient_id'], $candidate['sequence']);
        }

        $this->info($due === []
            ? 'Nenhum lembrete devido.'
            : count($due).' lembrete(s) despachado(s).');

        return self::SUCCESS;
    }
}
