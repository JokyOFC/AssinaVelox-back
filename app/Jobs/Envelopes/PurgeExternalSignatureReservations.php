<?php

namespace App\Jobs\Envelopes;

use App\Services\Signing\External\ExternalSignatureService;
use App\Services\Signing\External\ExternalSigningFeature;
use App\Services\Signing\External\PendingSignatureFiles;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Varredura das reservas de assinatura externa (Fase 3 §3.4): vence as que passaram do prazo,
 * destrava incorporações interrompidas e apaga diretórios órfãos. Só IDs/nada no payload.
 *
 * O prazo também é conferido na hora (preparar e enviar recusam reserva vencida); esta
 * varredura existe para liberar a reserva e os arquivos de quem nunca voltou. Agendar a cada
 * 5 minutos na integração (routes/console.php está fora desta área — docs/fase-3/assinatura-externa-a3.md §10).
 */
class PurgeExternalSignatureReservations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue((string) config('assinavelox.queues.finalization', 'finalization'));
    }

    public function handle(ExternalSignatureService $service, PendingSignatureFiles $files): void
    {
        $service->expireStale();
        $files->purgeOlderThan(max(60, ExternalSigningFeature::ttlMinutes() + 30));
    }
}
