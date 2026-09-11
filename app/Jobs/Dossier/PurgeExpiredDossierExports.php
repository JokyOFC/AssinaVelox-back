<?php

namespace App\Jobs\Dossier;

use App\Services\Dossier\DossierExports;
use App\Services\Dossier\Models\DossierExport;
use App\Services\Retention\HoldSnapshot;
use App\Services\Retention\LegalHolds;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Apaga os arquivos de dossiê vencidos (Q23: "arquivo gerado apagado depois do prazo").
 *
 * Despachado com atraso por cada montagem e seguro para rodar a qualquer momento: só toca no
 * que já venceu. A linha fica (`expired`, `purged_at`) como registro de que houve exportação.
 * Agendado de hora em hora em routes/console.php.
 *
 * Preservação legal (retencao-e-preservacao.md §5.5): o ZIP de um dossiê que contém QUALQUER
 * documento preservado (bloqueio do documento, da pasta ou da organização) não sai — o link
 * assinado vence sozinho, mas o arquivo só é apagado depois da liberação (a próxima execução
 * depois dela). A tentativa bloqueada entra na trilha no máximo uma vez por dia.
 */
class PurgeExpiredDossierExports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue((string) config('assinavelox.dossier.queue', 'default'));
    }

    public function handle(DossierExports $exports): void
    {
        $holds = app(LegalHolds::class);
        /** @var array<int, HoldSnapshot> $snapshots */
        $snapshots = [];

        DossierExport::withoutOrganizationScope()
            ->whereNull('purged_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->orderBy('id')
            ->chunkById(200, function ($batch) use ($exports, $holds, &$snapshots): void {
                foreach ($batch as $export) {
                    $organizationId = (int) $export->organization_id;
                    $snapshots[$organizationId] ??= $holds->snapshot($organizationId);
                    $hold = $holds->coveringAny($snapshots[$organizationId], $export->envelopeIds());

                    if ($hold !== null) {
                        $holds->recordBlocked($organizationId, 'dossier_expiry', $hold);

                        continue;
                    }

                    $exports->purge($export);
                }
            });
    }
}
