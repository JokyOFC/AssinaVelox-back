<?php

namespace App\Console\Commands;

use App\Services\Organizations\DailyDigest;
use Illuminate\Console\Command;

/**
 * "Resumo diário de pendências" (ROUTES §2.14, evento `daily_digest`).
 *
 * A tela de Notificações promete o horário — "enviado às 08:00 (America/Sao_Paulo)" — e até
 * esta correção nenhum agendamento o cumpria. Este comando é a casca agendável; a regra
 * (dia útil, só com pendência, visibilidade por papel, idempotência por dia) está em
 * `App\Services\Organizations\DailyDigest`.
 */
class DailyDigestCommand extends Command
{
    protected $signature = 'notifications:daily-digest {--limit= : máximo de memberships nesta execução}';

    protected $description = 'Envia o resumo diário de pendências a quem manteve a preferência ligada.';

    public function handle(DailyDigest $digest): int
    {
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;

        $result = $digest->run(limit: $limit);

        $this->info(sprintf(
            'Resumo diário: %d enviado(s), %d sem envio (preferência desligada, fim de semana, já enviado hoje ou sem pendências).',
            $result['sent'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
