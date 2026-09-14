<?php

namespace App\Services\Ltv;

use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Jobs\Ltv\RefreshArchiveTimestamp;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use App\Models\VerificationRecordDocument;
use App\Services\Documents\DocumentStorage;
use App\Services\Envelopes\Finalization\FinalizationArtifacts;
use App\Services\Ltv\Exceptions\LtvException;
use App\Services\Ltv\Models\LtvOperation;
use App\Services\Ltv\Models\VerificationHashEntry;
use App\Services\Signing\Certificates\EnvelopeSigningLock;
use App\Services\Timestamp\Exceptions\TsaException;
use App\Services\Timestamp\Exceptions\TsaUnavailableException;
use App\Services\Timestamp\Models\OperatorTsaIssuance;
use App\Services\Timestamp\OperatorTsa;
use App\Services\Timestamp\OperatorTsaSerials;
use App\Services\Timestamp\TsaToolRunner;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-carimbo de arquivamento (B-LTA) do arquivo final de um envelope concluído — base do job
 * {@see RefreshArchiveTimestamp}. Atrás de `pades_ltv`.
 *
 * ## Serializado com o pipeline
 *
 * Roda sob o MESMO lock do envelope que as assinaturas de participante e a finalização usam
 * ({@see EnvelopeSigningLock}): um gravador de revisão por vez. Tudo é relido DEPOIS de obter o lock.
 *
 * ## Idempotente
 *
 * O chamador informa as versões finais que viu (`$sourceVersionIds`). Só é renovado o documento
 * cuja versão final VIGENTE ainda é uma delas; depois do re-carimbo a vigente é outra e a
 * repetição não faz nada. `ltv_operations.idempotency_key` (registro + posição + versão de
 * origem) é a segunda barreira. Um serial é reservado por tentativa e nunca reaproveitado.
 *
 * ## O que muda
 *
 * Nova `document_version` (`kind=final`), novos ponteiros (documento, envelope, registro), novo
 * `final_sha256`, histórico de resumos ({@see VerificationHashHistory}) e `ltv_*`
 * ({@see LtvState}). `signature_profile` e `signature_status` NÃO mudam (T2).
 *
 * Falha (TSA indisponível, material de revogação ausente, certificado do último carimbo vencido)
 * não publica nada: o arquivo vigente continua válido; a operação fica `failed` com o código e o
 * job tenta de novo com backoff (T5).
 */
final class ArchiveTimestampRefresher
{
    public const REFRESHED = 'refreshed';

    public const ALREADY_DONE = 'already_done';

    public const SKIPPED = 'skipped';

    public const DISABLED = 'disabled';

    public function __construct(
        private readonly OperatorTsa $tsa,
        private readonly OperatorTsaSerials $serials,
        private readonly TsaToolRunner $runner,
        private readonly FinalizationArtifacts $artifacts,
        private readonly DocumentStorage $storage,
        private readonly EnvelopeSigningLock $lock,
        private readonly LtvState $state,
        private readonly VerificationHashHistory $history,
        private readonly LtvConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  list<int>|null  $sourceVersionIds  versões finais vistas pelo chamador; null = as vigentes
     *
     * @throws LockTimeoutException outro gravador segura o envelope além da espera
     * @throws TsaUnavailableException|TsaException|LtvException
     */
    public function refresh(int $verificationRecordId, ?array $sourceVersionIds = null, ?string $correlationId = null, ?int $lockWaitSeconds = null): string
    {
        if (! LtvFeatures::enabled()) {
            return self::DISABLED;
        }

        $record = VerificationRecord::query()->find($verificationRecordId);

        if ($record === null || $record->isRevoked()) {
            return self::SKIPPED;
        }

        $correlationId ??= (string) Str::ulid();

        return $this->lock->run(
            (int) $record->envelope_id,
            fn (): string => $this->refreshLocked($verificationRecordId, $sourceVersionIds, $correlationId),
            $lockWaitSeconds ?? $this->config->lockWaitSeconds(),
        );
    }

    /**
     * @param  list<int>|null  $sourceVersionIds
     */
    private function refreshLocked(int $recordId, ?array $sourceVersionIds, string $correlationId): string
    {
        $record = VerificationRecord::query()->with('documents')->find($recordId);

        if ($record === null || $record->isRevoked()) {
            return self::SKIPPED;
        }

        $envelope = Envelope::withoutOrganizationScope()->find($record->envelope_id);

        if (! $envelope instanceof Envelope || $envelope->status !== EnvelopeStatus::Completed) {
            return self::SKIPPED;
        }

        $targets = $this->targets($record);

        if ($sourceVersionIds !== null) {
            $wanted = array_map('intval', $sourceVersionIds);
            $targets = array_values(array_filter(
                $targets,
                fn (array $target): bool => in_array((int) $target['version']->getKey(), $wanted, true),
            ));
        }

        if ($targets === []) {
            return self::ALREADY_DONE;
        }

        $reasons = $this->tsa->unavailableReasons();

        if ($reasons !== []) {
            throw new TsaUnavailableException($reasons);
        }

        $refreshed = false;

        foreach ($targets as $target) {
            $refreshed = $this->refreshOne($record, $envelope, $target, $correlationId) || $refreshed;
        }

        return $refreshed ? self::REFRESHED : self::ALREADY_DONE;
    }

    /**
     * @return list<array{position: int, row: VerificationRecordDocument|null, document: Document, version: DocumentVersion}>
     */
    private function targets(VerificationRecord $record): array
    {
        $targets = [];

        foreach ($record->documents as $row) {
            $version = $row->final_document_version_id !== null
                ? DocumentVersion::query()->withoutGlobalScopes()->find($row->final_document_version_id)
                : null;

            if (! $version instanceof DocumentVersion) {
                continue;
            }

            $document = Document::query()->withoutGlobalScopes()->find($row->document_id ?? $version->document_id);

            if ($document instanceof Document) {
                $targets[] = ['position' => (int) $row->position, 'row' => $row, 'document' => $document, 'version' => $version];
            }
        }

        if ($targets !== [] || $record->final_document_version_id === null) {
            return $targets;
        }

        $version = DocumentVersion::query()->withoutGlobalScopes()->find($record->final_document_version_id);
        $document = $version instanceof DocumentVersion ? Document::query()->withoutGlobalScopes()->find($version->document_id) : null;

        if ($version instanceof DocumentVersion && $document instanceof Document) {
            $targets[] = ['position' => 1, 'row' => null, 'document' => $document, 'version' => $version];
        }

        return $targets;
    }

    /**
     * @param  array{position: int, row: VerificationRecordDocument|null, document: Document, version: DocumentVersion}  $target
     */
    private function refreshOne(VerificationRecord $record, Envelope $envelope, array $target, string $correlationId): bool
    {
        $source = $target['version'];
        $key = sprintf('refresh:%d:%d:%d', (int) $record->getKey(), $target['position'], (int) $source->getKey());

        /** @var LtvOperation|null $operation */
        $operation = LtvOperation::query()->where('idempotency_key', $key)->first();

        if ($operation !== null && $operation->isDone()) {
            return false;
        }

        $operation ??= new LtvOperation;
        $operation->forceFill([
            'ulid' => $operation->exists ? $operation->ulid : (string) Str::ulid(),
            'kind' => LtvOperation::KIND_REFRESH,
            'idempotency_key' => $key,
            'organization_id' => $envelope->organization_id,
            'envelope_id' => $envelope->getKey(),
            'verification_record_id' => $record->getKey(),
            'document_id' => $target['document']->getKey(),
            'source_document_version_id' => $source->getKey(),
            'source_sha256' => $source->sha256,
            'status' => LtvOperation::STATUS_RUNNING,
            'requested_level' => 'B-LTA',
            'error_code' => null,
            'attempts' => ($operation->exists ? (int) $operation->attempts : 0) + 1,
            'correlation_id' => $correlationId,
            'started_at' => Carbon::now(),
            'finished_at' => null,
        ])->save();

        // Serial reservado em transação curta, ANTES do pdftool (regra da onda C).
        $issuance = $this->serials->reserve('ltv_archive', (int) $envelope->organization_id, $correlationId);
        $workDir = $this->runner->temporaryDirectory('ltv-');

        try {
            $in = $this->storage->copyToTemporary($source, $workDir, 'vigente.pdf');
            $out = $workDir->path('renovado.pdf');
            $tsaConfig = $this->tsa->config();

            try {
                $report = $this->runner->run('ltv-refresh', [
                    '--in', $in,
                    '--out', $out,
                    '--tsa-kind', 'operator',
                    '--tsa-pfx', (string) $tsaConfig->pfxPath(),
                    '--tsa-pass-env', $tsaConfig->passwordEnv(),
                    '--tsa-serial', $issuance->serial,
                    '--tsa-policy-oid', $tsaConfig->policyOid(),
                    '--tsa-accuracy-ms', (string) $tsaConfig->accuracyMs(),
                    ...$this->config->revinfoArguments(),
                ], [$tsaConfig->passwordEnv()], $this->config->timeout(), $workDir, $correlationId);

                $stamp = is_array($report['timestamps'] ?? null) && is_array($report['timestamps'][0] ?? null) ? $report['timestamps'][0] : null;

                if ($stamp === null) {
                    throw new LtvException('O pdftool não devolveu o carimbo de arquivamento.', 'no_timestamp_embedded');
                }

                $sha256 = hash_file('sha256', $out);

                if (! is_string($sha256) || $sha256 !== ($report['sha256'] ?? null)) {
                    throw new LtvException('O arquivo renovado não confere com o relatório do pdftool.', 'output_mismatch');
                }
            } catch (Throwable $exception) {
                $this->fail($operation, $issuance, $exception, $correlationId);

                throw $exception;
            }

            /** @var array<string, mixed> $stamp */
            $this->serials->markGranted($issuance, $stamp);
            // Bytes primeiro; só depois as linhas do banco.
            $new = $this->artifacts->store($envelope, $target['document'], DocumentVersionKind::Final, $out, $correlationId);
        } finally {
            $workDir->delete();
        }

        $now = Carbon::now();

        DB::transaction(function () use ($record, $envelope, $target, $source, $new, $report, $operation, $issuance, $now): void {
            Document::query()->withoutGlobalScopes()->whereKey($target['document']->getKey())->update(['final_version_id' => $new->getKey()]);

            if ($target['row'] !== null) {
                $target['row']->forceFill(['final_sha256' => $new->sha256, 'final_document_version_id' => $new->getKey()])->save();
            }

            if ((int) $record->final_document_version_id === (int) $source->getKey()) {
                $record->forceFill(['final_document_version_id' => $new->getKey(), 'final_sha256' => $new->sha256])->save();
            }

            Envelope::withoutOrganizationScope()
                ->whereKey($envelope->getKey())
                ->where('final_document_version_id', $source->getKey())
                ->update(['final_document_version_id' => $new->getKey()]);

            $status = $this->state->apply($record, $report, $now);
            $this->history->recordReplacement($record, $target['position'], $target['row'], $source, $new, VerificationHashEntry::REASON_LTV_REFRESH, $status, $now);

            $operation->forceFill([
                'status' => LtvOperation::STATUS_COMPLETED,
                'result_document_version_id' => $new->getKey(),
                'result_sha256' => $new->sha256,
                'effective_level' => is_string($report['effective_level'] ?? null) ? $report['effective_level'] : null,
                'serials' => ['used' => [$issuance->serial], 'unused' => []],
                'finished_at' => $now,
            ])->save();
        });

        $this->logger->info('LTV: carimbo de arquivamento renovado.', [
            'envelope_id' => $envelope->getKey(),
            'verification_record_id' => $record->getKey(),
            'position' => $target['position'],
            'source_version' => $source->ulid,
            'result_version' => $new->ulid,
            'effective_level' => $report['effective_level'] ?? null,
            'tsa_serial' => $issuance->serial,
            'correlation_id' => $correlationId,
        ]);

        return true;
    }

    private function fail(LtvOperation $operation, OperatorTsaIssuance $issuance, Throwable $exception, string $correlationId): void
    {
        $code = match (true) {
            $exception instanceof TsaException => $exception->errorCode,
            $exception instanceof LtvException => $exception->errorCode,
            default => 'unexpected_error',
        };

        $this->serials->markFailed($issuance, $code);
        $operation->forceFill([
            'status' => LtvOperation::STATUS_FAILED,
            'error_code' => mb_substr($code, 0, 64),
            'serials' => ['used' => [], 'unused' => [$issuance->serial]],
            'finished_at' => Carbon::now(),
        ])->save();

        $this->logger->warning('LTV: re-carimbo de arquivamento falhou; o arquivo vigente continua válido.', [
            'ltv_operation' => $operation->ulid,
            'error_code' => $code,
            'exception' => $exception::class,
            'correlation_id' => $correlationId,
        ]);
    }
}
