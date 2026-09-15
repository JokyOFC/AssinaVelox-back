<?php

namespace App\Services\BulkGeneration;

use App\Models\Organization;
use App\Models\Plan;

/**
 * Limites do lote: globais (`assinavelox.bulk_generation.*`) e, opcionalmente, por plano em
 * `plans.features.bulk_generation_limits` ({max_rows, max_file_bytes, max_concurrent_batches,
 * concurrency}). Vale o MENOR dos dois: o plano pode apertar o limite da instalação, nunca
 * ampliá-lo.
 */
final class BulkGenerationLimits
{
    public const PLAN_KEY = 'bulk_generation_limits';

    public function __construct(
        public readonly int $maxRows,
        public readonly int $maxFileBytes,
        public readonly int $maxConcurrentBatches,
        public readonly int $concurrency,
        public readonly int $maxColumns,
        public readonly int $maxCellChars,
        public readonly int $maxUncompressedBytes,
    ) {}

    public static function for(?Organization $organization): self
    {
        $global = [
            'max_rows' => max(1, (int) config('assinavelox.bulk_generation.max_rows', 1000)),
            'max_file_bytes' => max(1024, (int) config('assinavelox.bulk_generation.max_file_bytes', 5 * 1024 * 1024)),
            'max_concurrent_batches' => max(1, (int) config('assinavelox.bulk_generation.max_concurrent_batches', 2)),
            'concurrency' => max(1, (int) config('assinavelox.bulk_generation.concurrency_per_organization', 3)),
        ];

        /** @var Plan|null $plan */
        $plan = $organization?->currentSubscription()->with('plan')->first()?->plan;
        $planLimits = $plan?->features[self::PLAN_KEY] ?? null;

        if (is_array($planLimits)) {
            foreach ($global as $key => $value) {
                $override = $planLimits[$key] ?? null;

                if (is_int($override) && $override > 0) {
                    $global[$key] = min($value, $override);
                }
            }
        }

        return new self(
            maxRows: $global['max_rows'],
            maxFileBytes: $global['max_file_bytes'],
            maxConcurrentBatches: $global['max_concurrent_batches'],
            concurrency: $global['concurrency'],
            maxColumns: max(2, (int) config('assinavelox.bulk_generation.max_columns', 100)),
            maxCellChars: max(100, (int) config('assinavelox.bulk_generation.max_cell_chars', 5000)),
            maxUncompressedBytes: max(1024 * 1024, (int) config('assinavelox.bulk_generation.max_uncompressed_bytes', 50 * 1024 * 1024)),
        );
    }

    /**
     * @return array{max_rows: int, max_file_bytes: int, max_concurrent_batches: int, max_columns: int, max_cell_chars: int}
     */
    public function toArray(): array
    {
        return [
            'max_rows' => $this->maxRows,
            'max_file_bytes' => $this->maxFileBytes,
            'max_concurrent_batches' => $this->maxConcurrentBatches,
            'max_columns' => $this->maxColumns,
            'max_cell_chars' => $this->maxCellChars,
        ];
    }
}
