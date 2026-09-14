<?php

namespace App\Jobs\Ltv;

use App\Models\VerificationRecord;
use App\Models\VerificationRecordDocument;
use App\Services\Ltv\LtvConfig;
use App\Services\Ltv\LtvFeatures;
use App\Services\Ltv\LtvStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Agendador do re-carimbo (P3-LTV): despacha {@see RefreshArchiveTimestamp} para os registros
 * B-LTA cujo `ltv_next_refresh_at` já chegou (vencimento do certificado da TSA − margem).
 *
 * Pendência de integração (fora da área): `Schedule::job(new ScheduleArchiveTimestampRefreshes)->daily()`
 * em `routes/console.php`.
 */
class ScheduleArchiveTimestampRefreshes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(LtvConfig $config): int
    {
        if (! LtvFeatures::enabled()) {
            return 0;
        }

        $records = VerificationRecord::query()
            ->with('documents')
            ->where('ltv_status', LtvStatus::BLta->value)
            ->whereNotNull('ltv_next_refresh_at')
            ->where('ltv_next_refresh_at', '<=', Carbon::now()->format('Y-m-d H:i:s'))
            ->whereNull('revoked_at')
            ->orderBy('ltv_next_refresh_at')
            ->limit($config->refreshBatchSize())
            ->get();

        foreach ($records as $record) {
            $sources = $record->documents->isNotEmpty()
                ? $record->documents->map(fn (VerificationRecordDocument $row): ?int => $row->final_document_version_id)->filter()->values()->all()
                : array_filter([$record->final_document_version_id]);

            RefreshArchiveTimestamp::dispatch(
                (int) $record->getKey(),
                (int) $record->envelope_id,
                array_values(array_map('intval', $sources)),
            );
        }

        return $records->count();
    }
}
