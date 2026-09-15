<?php

namespace App\Services\BulkGeneration;

use App\Models\BulkGeneration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retenção do lote NÃO confirmado (revisão adversarial da onda F; LGPD art. 15/16 —
 * docs/fase-3/geracao-em-lote.md §8).
 *
 * A planilha (nomes, e-mails, CPFs…) e o `payload` cifrado das linhas válidas só servem para
 * confirmar o lote. Lote em `draft` ou `validated` sem alteração há mais de
 * `bulk_generation.unconfirmed_retention_days` (padrão 7) é descartado exatamente como o botão
 * "Descartar" faz ({@see BulkGenerationManager::discard()}): arquivo, linhas e registro. Nada foi
 * reservado nem criado por um lote não confirmado. Lote confirmado nunca é tocado aqui.
 *
 * O registro fica no log da aplicação (ULID do lote, organização e idade — nunca nome de arquivo
 * ou conteúdo). Idempotente; inerte quando não há lote antigo (sempre, com a flag desligada).
 */
final class BulkGenerationRetention
{
    public function __construct(private readonly BulkGenerationManager $manager) {}

    public static function days(): int
    {
        return max(1, (int) config('assinavelox.bulk_generation.unconfirmed_retention_days', 7));
    }

    /**
     * @return int lotes descartados
     */
    public function prune(?Carbon $now = null, int $limit = 500): int
    {
        $cutoff = ($now ?? Carbon::now())->copy()->subDays(self::days());
        $discarded = 0;

        $batches = BulkGeneration::withoutOrganizationScope()
            ->whereIn('status', [BulkGenerationStatus::Draft->value, BulkGenerationStatus::Validated->value])
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($batches as $batch) {
            try {
                // Relido sob lock: um lote confirmado (ou alterado) entre a consulta e o descarte fica.
                $gone = DB::transaction(function () use ($batch, $cutoff): bool {
                    $fresh = BulkGeneration::withoutOrganizationScope()->whereKey($batch->getKey())->lockForUpdate()->first();

                    if ($fresh === null || ! $fresh->status->isEditable() || $fresh->updated_at === null || $fresh->updated_at->gte($cutoff)) {
                        return false;
                    }

                    $this->manager->discard($fresh);

                    return true;
                });

                if (! $gone) {
                    continue;
                }

                $discarded++;

                Log::info('bulk: lote não confirmado descartado pelo prazo de retenção.', [
                    'bulk_generation' => $batch->ulid,
                    'organization_id' => $batch->organization_id,
                    'status' => $batch->status->value,
                    'retention_days' => self::days(),
                ]);
            } catch (Throwable $exception) {
                Log::warning('bulk: não foi possível descartar um lote não confirmado.', [
                    'bulk_generation' => $batch->ulid,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $discarded;
    }
}
