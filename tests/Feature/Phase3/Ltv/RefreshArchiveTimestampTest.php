<?php

use App\Enums\DocumentVersionKind;
use App\Jobs\Ltv\RefreshArchiveTimestamp;
use App\Models\DocumentVersion;
use App\Models\VerificationRecord;
use App\Services\Ltv\ArchiveTimestampRefresher;
use App\Services\Ltv\LtvState;
use App\Services\Ltv\LtvStatus;
use App\Services\Ltv\Models\LtvOperation;
use App\Services\Ltv\Models\VerificationHashEntry;
use App\Services\Ltv\VerificationHashHistory;
use App\Services\Signing\Certificates\EnvelopeSigningLock;
use App\Services\Timestamp\Exceptions\TsaException;
use App\Services\Timestamp\Exceptions\TsaUnavailableException;
use App\Services\Timestamp\Models\OperatorTsaIssuance;
use App\Services\Verification\PublicVerification;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/Support/LtvHelpers.php';

/*
|--------------------------------------------------------------------------
| P3-LTV — RefreshArchiveTimestamp: re-carimbo de arquivamento (pdftool real)
|--------------------------------------------------------------------------
| Idempotente, serializado com o pipeline (lock do envelope), histórico de hashes aditivo, perfil
| exibido PAdES-B-B e nada ICP-Brasil.
*/

beforeEach(function () {
    Notification::fake();
    $this->work = ktsaWorkspace($this);
    $this->pki = ltvSetup($this->work);
    $this->scenario = ltvCompletedEnvelopeWithLtaFinal($this->work, $this->pki);
});

afterEach(function () {
    ltvCleanup($this->work ?? null);
});

function ltvFinalVersions(int $documentId): int
{
    return DocumentVersion::query()->withoutGlobalScopes()->where('document_id', $documentId)->where('kind', DocumentVersionKind::Final->value)->count();
}

it('re-carimba: nova versão final, novo hash, histórico com o anterior e perfil exibido PAdES-B-B', function () {
    ['record' => $record, 'envelope' => $envelope, 'version' => $before] = $this->scenario;
    $history = app(VerificationHashHistory::class);

    expect(LtvState::status($record))->toBe(LtvStatus::BLta)
        ->and($history->publicProps($record))->toBe([]);

    ltvRunRefresh($record, [(int) $before->getKey()]);

    $record->refresh();
    $envelope->refresh();
    $after = DocumentVersion::query()->withoutGlobalScopes()->findOrFail($record->final_document_version_id);

    expect($after->getKey())->not->toBe($before->getKey())
        ->and($after->kind)->toBe(DocumentVersionKind::Final)
        ->and($after->version_number)->toBeGreaterThan($before->version_number)
        ->and($envelope->final_document_version_id)->toBe($after->getKey())
        ->and($record->final_sha256)->toBe($after->sha256)
        ->and($record->final_sha256)->not->toBe($before->sha256)
        // T2: o perfil gravado e exibido não muda; o estado técnico continua B-LTA.
        ->and($record->signature_profile)->toBe('PAdES-B-B')
        ->and(LtvState::status($record))->toBe(LtvStatus::BLta)
        ->and($record->getAttribute('ltv_next_refresh_at'))->not->toBeNull();

    // Incremental: o arquivo renovado contém, byte a byte, o anterior, e ganhou uma camada.
    $oldBytes = (string) file_get_contents(finalizationDownload($before, $this->work.DIRECTORY_SEPARATOR.'antes.pdf'));
    $newPath = finalizationDownload($after, $this->work.DIRECTORY_SEPARATOR.'depois.pdf');
    expect(str_starts_with((string) file_get_contents($newPath), $oldBytes))->toBeTrue()
        ->and(hash_file('sha256', $newPath))->toBe($record->final_sha256);

    $report = ltvValidate($newPath, $this->pki);
    expect($report['document_timestamp_count'])->toBe(2)
        ->and($report['effective_level'])->toBe('B-LTA')
        ->and($report['timestamp_chain_valid'])->toBeTrue();

    // Histórico: o anterior (substituído, com as datas) e o vigente.
    $entries = $history->entries($record);
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->sha256)->toBe($before->sha256)
        ->and($entries[0]->reason)->toBe(VerificationHashEntry::REASON_FINALIZED)
        ->and($entries[0]->superseded_at)->not->toBeNull()
        ->and($entries[1]->sha256)->toBe($after->sha256)
        ->and($entries[1]->isCurrent())->toBeTrue()
        ->and($entries[1]->reason)->toBe(VerificationHashEntry::REASON_LTV_REFRESH);

    $props = $history->publicProps($record);
    expect($props['hash_history'])->toHaveCount(2)
        ->and($props['hash_history'][1]['current'])->toBeTrue()
        ->and($props['hash_history_notice'])->toContain('sem alterar o conteúdo');

    expect($history->match($record, $before->sha256))->toMatchArray(['matches' => 'signed_previous', 'position' => 1])
        ->and($history->match($record, $after->sha256))->toBeNull();

    // A verificação pública continua anunciando PAdES-B-B e publica o resumo vigente.
    $public = app(PublicVerification::class)->result($envelope->fresh());
    expect($public['signature_profile'])->toBe('PAdES-B-B')
        ->and($public['hashes']['final_sha256'])->toBe($after->sha256)
        ->and(json_encode($public))->not->toContain('"tsa_kind":"icp_brasil"')
        ->and(json_encode($public))->not->toContain('PAdES-B-LTA');

    $archive = OperatorTsaIssuance::query()->where('purpose', 'ltv_archive')->sole();
    expect($archive->status)->toBe(OperatorTsaIssuance::STATUS_GRANTED)
        ->and($archive->environment)->toBe('test');

    $operation = LtvOperation::query()->where('kind', LtvOperation::KIND_REFRESH)->sole();
    expect($operation->status)->toBe(LtvOperation::STATUS_COMPLETED)
        ->and($operation->source_sha256)->toBe($before->sha256)
        ->and($operation->result_sha256)->toBe($after->sha256)
        ->and($operation->serials)->toBe(['used' => [$archive->serial], 'unused' => []]);
});

it('é idempotente: repetir o job com a mesma origem não gera outra camada nem gasta serial', function () {
    ['record' => $record, 'version' => $before, 'document' => $document] = $this->scenario;
    $finals = ltvFinalVersions((int) $document->getKey());

    ltvRunRefresh($record, [(int) $before->getKey()]);
    $firstHash = $record->refresh()->final_sha256;

    ltvRunRefresh($record, [(int) $before->getKey()]);
    expect(app(ArchiveTimestampRefresher::class)->refresh((int) $record->getKey(), [(int) $before->getKey()]))
        ->toBe(ArchiveTimestampRefresher::ALREADY_DONE);

    expect($record->refresh()->final_sha256)->toBe($firstHash)
        ->and(ltvFinalVersions((int) $document->getKey()))->toBe($finals + 1)
        ->and(OperatorTsaIssuance::query()->where('purpose', 'ltv_archive')->count())->toBe(1)
        ->and(LtvOperation::query()->where('kind', LtvOperation::KIND_REFRESH)->count())->toBe(1)
        ->and(app(VerificationHashHistory::class)->entries($record))->toHaveCount(2);
});

it('é serializado com o pipeline: espera o lock do envelope e não toca em nada enquanto ele está ocupado', function () {
    ['record' => $record, 'version' => $before, 'envelope' => $envelope] = $this->scenario;
    config()->set('assinavelox.ltv.refresh.lock_wait_seconds', 0);

    $job = new RefreshArchiveTimestamp((int) $record->getKey(), (int) $envelope->getKey(), [(int) $before->getKey()]);
    $middleware = $job->middleware();
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe(EnvelopeSigningLock::key((int) $envelope->getKey()));

    // Outro gravador (assinatura de participante, finalização) segura o envelope.
    $lock = Cache::lock(EnvelopeSigningLock::key((int) $envelope->getKey()), 60);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(ArchiveTimestampRefresher::class)->refresh((int) $record->getKey(), [(int) $before->getKey()], null, 0))
            ->toThrow(LockTimeoutException::class);

        // O job, com o lock ocupado, devolve à fila (sem efeito fora do worker) e não muda nada.
        app()->call([$job, 'handle']);
    } finally {
        $lock->release();
    }

    expect($record->refresh()->final_sha256)->toBe($before->sha256)
        ->and(OperatorTsaIssuance::query()->where('purpose', 'ltv_archive')->count())->toBe(0)
        ->and(LtvOperation::query()->where('kind', LtvOperation::KIND_REFRESH)->count())->toBe(0);

    // Liberado o envelope, a mesma chamada renova.
    expect(app(ArchiveTimestampRefresher::class)->refresh((int) $record->getKey(), [(int) $before->getKey()], null, 0))
        ->toBe(ArchiveTimestampRefresher::REFRESHED);
});

it('TSA não configurada: nada é publicado e o job pode tentar de novo', function () {
    ['record' => $record, 'version' => $before] = $this->scenario;
    config()->set('assinavelox.tsa.pfx_path', $this->work.DIRECTORY_SEPARATOR.'nao-existe.pfx');

    expect(fn () => app(ArchiveTimestampRefresher::class)->refresh((int) $record->getKey(), [(int) $before->getKey()]))
        ->toThrow(TsaUnavailableException::class);

    expect($record->refresh()->final_sha256)->toBe($before->sha256)
        ->and(OperatorTsaIssuance::query()->where('purpose', 'ltv_archive')->count())->toBe(0);
});

it('sem material de revogação novo o re-carimbo falha de forma explícita e o arquivo vigente continua', function () {
    ['record' => $record, 'version' => $before] = $this->scenario;
    config()->set('assinavelox.ltv.crl_paths', []);

    expect(fn () => app(ArchiveTimestampRefresher::class)->refresh((int) $record->getKey(), [(int) $before->getKey()]))
        ->toThrow(TsaException::class);

    $operation = LtvOperation::query()->where('kind', LtvOperation::KIND_REFRESH)->sole();
    expect($operation->status)->toBe(LtvOperation::STATUS_FAILED)
        ->and($operation->error_code)->toBe('restamp_failed')
        ->and($operation->attempts)->toBe(1);

    expect(OperatorTsaIssuance::query()->where('purpose', 'ltv_archive')->sole()->status)->toBe(OperatorTsaIssuance::STATUS_FAILED)
        ->and($record->refresh()->final_sha256)->toBe($before->sha256)
        ->and(app(VerificationHashHistory::class)->entries($record))->toHaveCount(0);

    // A retentativa reaproveita a mesma operação (mesma origem) e, com a CRL de volta, conclui.
    config()->set('assinavelox.ltv.crl_paths', [$this->pki['crl']]);
    expect(app(ArchiveTimestampRefresher::class)->refresh((int) $record->getKey(), [(int) $before->getKey()]))
        ->toBe(ArchiveTimestampRefresher::REFRESHED);
    expect($operation->refresh()->status)->toBe(LtvOperation::STATUS_COMPLETED)
        ->and($operation->attempts)->toBe(2)
        ->and(OperatorTsaIssuance::query()->where('purpose', 'ltv_archive')->count())->toBe(2);
});

it('envelope revogado não é re-carimbado', function () {
    ['record' => $record, 'version' => $before] = $this->scenario;
    VerificationRecord::query()->whereKey($record->getKey())->update(['revoked_at' => now()]);

    expect(app(ArchiveTimestampRefresher::class)->refresh((int) $record->getKey(), [(int) $before->getKey()]))
        ->toBe(ArchiveTimestampRefresher::SKIPPED)
        ->and(OperatorTsaIssuance::query()->where('purpose', 'ltv_archive')->count())->toBe(0);
});
