<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Retention\RetentionRunner;
use Illuminate\Console\Command;

/**
 * Aplica as políticas de retenção ativas (Fase 2 §2.19 — docs/fase-2/retencao-e-preservacao.md):
 *
 *   php artisan retention:apply [--organization=ULID] [--dry-run]
 *
 * Agendado uma vez por dia (routes/console.php). Idempotente e em lotes; nada preservado sai;
 * inerte com a flag `retention_policies` desligada (nenhuma organização é selecionada).
 */
class RetentionApplyCommand extends Command
{
    protected $signature = 'retention:apply
        {--organization= : ULID de uma organização}
        {--dry-run : só conta o que seria apagado}';

    protected $description = 'Apaga o que venceu pela política de retenção de cada organização (respeitando as preservações).';

    public function handle(RetentionRunner $runner): int
    {
        $organizationId = null;
        $ulid = $this->option('organization');

        if (is_string($ulid) && $ulid !== '') {
            $organizationId = Organization::query()->where('ulid', $ulid)->value('id');

            if ($organizationId === null) {
                $this->error('Organização não encontrada.');

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = $runner->run($organizationId !== null ? (int) $organizationId : null, $dryRun);

        $this->info(sprintf(
            '%sOrganizações: %d. Documentos %s: %d. Preservados (não apagados): %d. Fotos: %d. Dossiês: %d. Eventos da trilha: %d. Retomados: %d. Falhas: %d.',
            $dryRun ? '[simulação] ' : '',
            $summary['organizations'],
            $dryRun ? 'que seriam apagados' : 'apagados',
            $dryRun ? $summary['envelopes_candidates'] : $summary['envelopes_purged'],
            $summary['held'],
            $summary['identity_captures'],
            $summary['dossiers'],
            $summary['audit_events'],
            $summary['resumed'],
            $summary['failed'],
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
