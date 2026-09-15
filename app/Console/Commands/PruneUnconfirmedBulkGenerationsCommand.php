<?php

namespace App\Console\Commands;

use App\Services\BulkGeneration\BulkGenerationRetention;
use Illuminate\Console\Command;

/**
 * Descarta lotes de geração em lote nunca confirmados depois do prazo de retenção
 * (docs/fase-3/geracao-em-lote.md §8). Agendado diariamente em routes/console.php.
 */
final class PruneUnconfirmedBulkGenerationsCommand extends Command
{
    protected $signature = 'bulk-generations:prune-unconfirmed {--limit=500 : lotes por execução}';

    protected $description = 'Descarta planilha e linhas de lotes em rascunho/validados sem confirmação há mais do prazo configurado';

    public function handle(BulkGenerationRetention $retention): int
    {
        $count = $retention->prune(null, max(1, (int) $this->option('limit')));

        $this->info(sprintf('%d lote(s) não confirmado(s) descartado(s).', $count));

        return self::SUCCESS;
    }
}
