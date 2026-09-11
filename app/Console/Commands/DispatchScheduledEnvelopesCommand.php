<?php

namespace App\Console\Commands;

use App\Services\Envelopes\Sending\ScheduledSend;
use Illuminate\Console\Command;

/**
 * Envio agendado (Fase 2 §2.5):
 *
 *   php artisan envelopes:dispatch-scheduled [--limit=200]
 *
 * Agendado a cada minuto (routes/console.php). Cancela agendamentos de envelopes editados
 * ou que saíram de `ready` e despacha um job por envelope cujo horário chegou. O disparo é
 * idempotente (reivindicação atômica do agendamento + trava do SendEnvelope).
 */
class DispatchScheduledEnvelopesCommand extends Command
{
    protected $signature = 'envelopes:dispatch-scheduled {--limit= : Máximo de envelopes examinados por execução}';

    protected $description = 'Envia os documentos cujo envio agendado venceu e cancela agendamentos invalidados.';

    public function handle(ScheduledSend $scheduler): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $result = $scheduler->sweep(null, $limit);

        $this->info(sprintf(
            '%d envio(s) agendado(s) despachado(s); %d agendamento(s) cancelado(s).',
            $result['dispatched'],
            $result['canceled'],
        ));

        return self::SUCCESS;
    }
}
