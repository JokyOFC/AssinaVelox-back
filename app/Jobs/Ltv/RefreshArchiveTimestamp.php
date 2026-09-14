<?php

namespace App\Jobs\Ltv;

use App\Services\Ltv\ArchiveTimestampRefresher;
use App\Services\Ltv\Exceptions\LtvException;
use App\Services\Ltv\LtvFeatures;
use App\Services\Signing\Certificates\EnvelopeSigningLock;
use App\Services\Timestamp\Exceptions\TsaException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-carimbo de arquivamento antes da expiração do certificado da TSA (roadmap §3.6, P3-LTV).
 *
 * - Só ids no payload (e as versões finais vistas no despacho, que dão a idempotência).
 * - `WithoutOverlapping(envelope:{id}:sign)` — a mesma chave do `ApplyParticipantSignature`: o
 *   re-carimbo não ocupa um worker enquanto o envelope tem outro gravador; o serviço, por sua
 *   vez, roda sob `EnvelopeSigningLock` (serializado com o pipeline).
 * - `ShouldBeUnique` por registro; 3 tentativas com backoff (TSA fora do ar é "tente depois", T5).
 * - Com `pades_ltv` desligada não faz nada.
 */
class RefreshArchiveTimestamp implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 1800;

    public int $timeout = 600;

    /**
     * @param  list<int>|null  $sourceVersionIds
     */
    public function __construct(
        public readonly int $verificationRecordId,
        public readonly int $envelopeId,
        public readonly ?array $sourceVersionIds = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('assinavelox.ltv.refresh.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'ltv-refresh:'.$this->verificationRecordId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(EnvelopeSigningLock::key($this->envelopeId)))
                ->releaseAfter(30)
                ->expireAfter(900),
        ];
    }

    public function handle(ArchiveTimestampRefresher $refresher, LoggerInterface $logger): void
    {
        if (! LtvFeatures::enabled()) {
            $logger->info('LTV: re-carimbo ignorado (pades_ltv desligada).', ['verification_record_id' => $this->verificationRecordId]);

            return;
        }

        $correlationId = $this->correlationId ?? (string) Str::ulid();

        try {
            $outcome = $refresher->refresh($this->verificationRecordId, $this->sourceVersionIds, $correlationId);
        } catch (LockTimeoutException) {
            $logger->info('LTV: envelope ocupado por outro gravador; re-carimbo reagendado.', [
                'verification_record_id' => $this->verificationRecordId,
                'correlation_id' => $correlationId,
            ]);
            $this->release(30);

            return;
        }

        $logger->info('LTV: re-carimbo processado.', [
            'verification_record_id' => $this->verificationRecordId,
            'outcome' => $outcome,
            'correlation_id' => $correlationId,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $code = match (true) {
            $exception instanceof TsaException => $exception->errorCode,
            $exception instanceof LtvException => $exception->errorCode,
            default => 'unexpected_error',
        };

        app(LoggerInterface::class)->error('LTV: re-carimbo de arquivamento falhou definitivamente.', [
            'verification_record_id' => $this->verificationRecordId,
            'envelope_id' => $this->envelopeId,
            'error_code' => $code,
            'exception' => $exception::class,
            'message' => Str::limit($exception->getMessage(), 500, ''),
            'correlation_id' => $this->correlationId,
            'alert' => 'ltv_refresh_failed',
        ]);
    }
}
