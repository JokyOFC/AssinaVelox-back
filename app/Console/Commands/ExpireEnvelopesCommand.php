<?php

namespace App\Console\Commands;

use App\Services\Envelopes\Sending\ExpireEnvelopes;
use Illuminate\Console\Command;

/**
 * Expira os envelopes cujo prazo venceu (RECONCILIACAO Q22):
 *
 *   php artisan envelopes:expire [--limit=200]
 *
 * Agendado a cada 15 minutos (routes/console.php). O comando é idempotente e
 * reprocessável: cada envelope é expirado sob lock e um segundo passo não faz nada. A
 * expiração TAMBÉM é revalidada a cada acesso do signatário — o comando existe para que
 * a lista do app e as notificações não fiquem defasadas, não como única barreira.
 */
class ExpireEnvelopesCommand extends Command
{
    protected $signature = 'envelopes:expire {--limit= : Máximo de envelopes por execução}';

    protected $description = 'Transiciona para "expirado" os documentos cujo prazo de assinatura venceu.';

    public function handle(ExpireEnvelopes $expiration): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $count = $expiration->sweep($limit);

        $this->info($count === 0
            ? 'Nenhum documento vencido.'
            : $count.' documento(s) marcado(s) como expirado(s).');

        return self::SUCCESS;
    }
}
