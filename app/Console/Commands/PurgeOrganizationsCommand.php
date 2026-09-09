<?php

namespace App\Console\Commands;

use App\Services\Organizations\OrganizationPurge;
use Illuminate\Console\Command;

/**
 * Exclusão efetiva das organizações cuja carência de 30 dias venceu (ROUTES §2.12, Q23).
 *
 * A tela de Configurações › Geral e segurança promete a remoção e a política de privacidade
 * publicada promete ao titular que "os dados são apagados dos sistemas ativos". Este comando
 * é o que cumpre a promessa; sem ele `deletion_requested_at` era apenas uma data guardada.
 *
 * Idempotente: uma organização já removida não volta a aparecer na lista. Toda a regra está
 * em `App\Services\Organizations\OrganizationPurge`; aqui é só a casca agendável.
 */
class PurgeOrganizationsCommand extends Command
{
    protected $signature = 'organizations:purge
        {--limit= : máximo de organizações nesta execução}
        {--dry-run : lista o que seria removido, sem remover nada}';

    protected $description = 'Apaga as organizações cuja exclusão foi solicitada e cuja carência já venceu.';

    public function handle(OrganizationPurge $purge): int
    {
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;

        $due = $purge->due(limit: $limit);

        if ($due->isEmpty()) {
            $this->info('Nenhuma organização com exclusão vencida.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($due as $organization) {
                $this->line(sprintf('Seria removida: %s (%s)', $organization->name, $organization->ulid));
            }

            $this->info(sprintf('%d organização(ões) com exclusão vencida.', $due->count()));

            return self::SUCCESS;
        }

        $purged = 0;

        foreach ($due as $organization) {
            $receipt = $purge->purge($organization);
            $purged++;

            $this->line(sprintf(
                'Removida: %s — %d usuário(s) e %d registro(s) apagados.',
                $receipt['organization'],
                $receipt['users_deleted'],
                array_sum($receipt['rows']),
            ));
        }

        $this->info(sprintf('%d organização(ões) removida(s).', $purged));

        return self::SUCCESS;
    }
}
