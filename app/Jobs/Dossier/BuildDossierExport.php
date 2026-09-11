<?php

namespace App\Jobs\Dossier;

use App\Models\Envelope;
use App\Models\Organization;
use App\Services\Dossier\BulkDossierBuilder;
use App\Services\Dossier\DossierBuilder;
use App\Services\Dossier\DossierException;
use App\Services\Dossier\DossierExports;
use App\Services\Dossier\Models\DossierExport;
use App\Services\Pdf\PdfToolClient;
use App\Services\Timestamp\TimestampFeatures;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Monta o ZIP de um pedido de dossiê (single ou bulk) em fila (roadmap §2.13: "download em
 * lote de 50 envelopes conclui em fila sem timeout HTTP").
 *
 * - Só o id viaja no payload; tudo é recarregado do banco.
 * - `ShouldBeUnique` + `WithoutOverlapping` por pedido: nunca dois workers no mesmo ZIP.
 * - Idempotente: pedido já pronto com arquivo no disco é devolvido sem refazer nada.
 * - Nenhuma transação aberta durante a montagem (pdftool, TSA, ZIP): só gravações curtas
 *   de status antes e depois.
 * - Bytes primeiro (disco), linha depois (`ready`).
 */
class BuildDossierExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $dossierExportId, public readonly ?string $correlationId = null)
    {
        $this->onQueue((string) config('assinavelox.dossier.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'dossier-export:'.$this->dossierExportId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('dossier-export:'.$this->dossierExportId))->expireAfter($this->timeout)->releaseAfter(60)];
    }

    public function handle(DossierBuilder $builder, BulkDossierBuilder $bulk, DossierExports $exports, PdfToolClient $pdftool, LoggerInterface $logger): void
    {
        $export = DossierExport::withoutOrganizationScope()->find($this->dossierExportId);

        if ($export === null || $export->status === DossierExport::STATUS_EXPIRED) {
            return;
        }

        if ($export->isReady() && $exports->fileExists($export)) {
            return;
        }

        $correlationId = $this->correlationId ?? (string) Str::ulid();
        $export->forceFill(['status' => DossierExport::STATUS_BUILDING, 'attempts' => $export->attempts + 1, 'error_code' => null])->save();

        $work = $pdftool->temporaryDirectory('dossier-');

        try {
            $zipPath = $work->path('dossie.zip');
            $organization = Organization::query()->findOrFail($export->organization_id);
            $stamp = TimestampFeatures::operatorTsa();

            if ($export->kind === DossierExport::KIND_SINGLE) {
                $envelope = Envelope::forOrganization($export->organization_id)->findOrFail($export->envelope_id);
                $built = $builder->build($envelope, $zipPath, $work, (int) $export->getKey(), $stamp, $correlationId);
                $filename = $envelope->display_code.'-dossie.zip';
            } else {
                $envelopes = Envelope::forOrganization($export->organization_id)
                    ->whereIn('id', $export->envelopeIds())
                    ->orderBy('number')
                    ->get();

                // Visibilidade conferida DE NOVO na montagem: quem pediu pode ter perdido acesso.
                $requester = $export->requester;
                $allowed = $envelopes->filter(fn (Envelope $envelope): bool => $requester !== null && $requester->can('download', $envelope));
                $skipped = array_values($envelopes->diff($allowed)->map(fn (Envelope $envelope): array => ['envelope' => $envelope->display_code, 'reason' => 'Sem permissão no momento da montagem.'])->all());

                $built = $bulk->build($allowed, $zipPath, $work, (int) $export->getKey(), $stamp, $skipped, $correlationId);
                $filename = 'dossies-'.Carbon::now()->format('Ymd-His').'.zip';
            }

            $disk = (string) config('assinavelox.dossier.disk', 'documents');
            $path = sprintf('%s/%s/%s/%s.zip', trim((string) config('assinavelox.upload.path_prefix', 'orgs'), '/'), $organization->ulid, trim((string) config('assinavelox.dossier.path', 'dossiers'), '/'), $export->ulid);

            $stream = fopen($built->path, 'rb');

            if ($stream === false) {
                throw new DossierException('Não foi possível ler o dossiê montado.', 'zip_failed');
            }

            try {
                Storage::disk($disk)->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $expiresAt = Carbon::now()->addHours(max(1, (int) config('assinavelox.dossier.ttl_hours', 24)));

            $export->forceFill([
                'status' => DossierExport::STATUS_READY,
                'storage_disk' => $disk,
                'storage_path' => $path,
                'sha256' => $built->sha256,
                'size_bytes' => $built->sizeBytes,
                'manifest_sha256' => $built->manifestSha256,
                'envelope_count' => $built->envelopeCount,
                'timestamp_status' => $built->timestampStatus,
                'timestamp_token_id' => $built->timestampTokenId,
                'completed_at' => Carbon::now(),
                'expires_at' => $expiresAt,
                'purged_at' => null,
            ])->save();

            // Apaga o arquivo depois do prazo, mesmo sem o agendador (o job só apaga o que venceu).
            PurgeExpiredDossierExports::dispatch()->delay($expiresAt->copy()->addMinute());

            $logger->info('Dossiê montado.', [
                'dossier_export' => $export->ulid,
                'kind' => $export->kind,
                'envelopes' => $built->envelopeCount,
                'timestamp' => $built->timestampStatus,
                'filename' => $filename,
                'correlation_id' => $correlationId,
            ]);
        } catch (DossierException $exception) {
            // Erro de negócio: repetir não muda nada.
            $export->forceFill(['status' => DossierExport::STATUS_FAILED, 'error_code' => $exception->errorCode])->save();
            $this->fail($exception);
        } finally {
            $work->delete();
        }
    }

    public function failed(Throwable $exception): void
    {
        $export = DossierExport::withoutOrganizationScope()->find($this->dossierExportId);

        if ($export !== null && $export->status !== DossierExport::STATUS_READY) {
            $export->forceFill([
                'status' => DossierExport::STATUS_FAILED,
                'error_code' => $export->error_code ?? ($exception instanceof DossierException ? $exception->errorCode : 'unexpected_error'),
            ])->save();
        }

        app(LoggerInterface::class)->error('Montagem do dossiê falhou.', [
            'dossier_export_id' => $this->dossierExportId,
            'exception' => $exception::class,
            'message' => Str::limit($exception->getMessage(), 300, ''),
            'correlation_id' => $this->correlationId,
        ]);
    }
}
