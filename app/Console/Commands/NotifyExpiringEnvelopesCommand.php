<?php

namespace App\Console\Commands;

use App\Services\Envelopes\Sending\ExpireEnvelopes;
use Illuminate\Console\Command;

/**
 * Avisa que o prazo está acabando (RECONCILIACAO Q22, "expira em 48 h"):
 *
 *   php artisan envelopes:notify-expiring [--hours=48] [--limit=200]
 *
 * Um aviso por envelope, marcado em `settings.expiring_warned_at` — reexecutar o comando
 * não gera enxurrada de e-mail. Signatários pendentes recebem um link novo (o anterior é
 * revogado, como em qualquer reenvio); quem enviou recebe o aviso conforme as preferências
 * de notificação da conta.
 *
 * Não é o lembrete automático recorrente (switch "a cada 2 dias") — esse é Fase 2.
 */
class NotifyExpiringEnvelopesCommand extends Command
{
    protected $signature = 'envelopes:notify-expiring
        {--hours= : Antecedência do aviso, em horas (padrão: configuração da aplicação)}
        {--limit= : Máximo de envelopes por execução}';

    protected $description = 'Avisa signatários pendentes e remetentes de que o prazo de assinatura está acabando.';

    public function handle(ExpireEnvelopes $expiration): int
    {
        $hours = $this->option('hours') !== null ? (int) $this->option('hours') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $count = $expiration->warnExpiring($hours, $limit);

        $this->info($count === 0
            ? 'Nenhum documento perto do prazo com pendências.'
            : $count.' documento(s) com aviso de prazo enviado.');

        return self::SUCCESS;
    }
}
