<?php

namespace App\Services\BulkGeneration;

use App\Jobs\BulkGeneration\GenerateEnvelopeFromRowJob;
use App\Models\BulkGeneration;
use App\Models\BulkGenerationRow;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Limite de concorrência POR ORGANIZAÇÃO (docs/fase-3/geracao-em-lote.md §5).
 *
 * Nunca há mais de `concurrency` linhas da organização em `queued|processing`, somando todos
 * os lotes em andamento. O orquestrador chama `pump()` uma vez; cada linha, ao terminar, chama
 * de novo e libera a vaga para a próxima. Sem Redis, sem funil externo: a vaga é a própria
 * linha no banco, reivindicada por UPDATE condicional sob o lock da organização.
 *
 * Fila `sync` (testes, instalação sem worker): o job roda dentro do `dispatch()`. A guarda de
 * reentrada impede que cada linha abra um novo nível de pilha — o laço externo continua
 * reivindicando até acabar.
 */
final class BulkGenerationPump
{
    private static bool $pumping = false;

    /**
     * @return int linhas despachadas
     */
    public function pump(int $organizationId): int
    {
        if (self::$pumping) {
            return 0;
        }

        self::$pumping = true;
        $dispatched = 0;

        try {
            while (true) {
                $claimed = $this->claim($organizationId);

                if ($claimed === []) {
                    break;
                }

                foreach ($claimed as $rowId) {
                    GenerateEnvelopeFromRowJob::dispatch($rowId);
                    $dispatched++;
                }
            }
        } finally {
            self::$pumping = false;
        }

        return $dispatched;
    }

    /**
     * @return list<int>
     */
    private function claim(int $organizationId): array
    {
        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($organizationId)->first();

        if ($organization === null) {
            return [];
        }

        $limit = BulkGenerationLimits::for($organization)->concurrency;

        /** @var list<int> */
        return DB::transaction(function () use ($organizationId, $limit): array {
            // Serializa os pumps da mesma organização (MySQL); no SQLite a transação já serializa.
            Organization::query()->whereKey($organizationId)->lockForUpdate()->first();

            $inFlight = BulkGenerationRow::withoutOrganizationScope()
                ->where('organization_id', $organizationId)
                ->whereIn('status', [BulkRowStatus::Queued->value, BulkRowStatus::Processing->value])
                ->count();

            $available = $limit - $inFlight;

            if ($available <= 0) {
                return [];
            }

            $running = BulkGeneration::withoutOrganizationScope()
                ->select('id')
                ->where('organization_id', $organizationId)
                ->where('status', BulkGenerationStatus::Running->value);

            $candidates = BulkGenerationRow::withoutOrganizationScope()
                ->where('organization_id', $organizationId)
                ->where('status', BulkRowStatus::Pending->value)
                ->whereIn('bulk_generation_id', $running)
                ->orderBy('bulk_generation_id')
                ->orderBy('row_index')
                ->limit($available)
                ->pluck('id');

            $claimed = [];

            foreach ($candidates as $id) {
                $updated = BulkGenerationRow::withoutOrganizationScope()
                    ->whereKey($id)
                    ->where('status', BulkRowStatus::Pending->value)
                    ->update(['status' => BulkRowStatus::Queued->value, 'updated_at' => Carbon::now()]);

                if ($updated === 1) {
                    $claimed[] = (int) $id;
                }
            }

            return $claimed;
        });
    }
}
