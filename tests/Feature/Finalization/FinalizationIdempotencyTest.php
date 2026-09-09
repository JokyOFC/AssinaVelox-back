<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Jobs\Envelopes\FinalizeEnvelope;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\VerificationRecord;
use App\Notifications\Envelopes\EnvelopeCompletedNotification;
use App\Services\Envelopes\Finalization\EnvelopeFinalizer;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\TemporaryDirectory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/FinalizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Idempotência e retomada da finalização
|--------------------------------------------------------------------------
| O que se prova aqui: repetir o job não duplica versões nem conclui duas vezes, e uma
| queda no MEIO do pipeline — inclusive entre gravar o arquivo e atualizar o banco — é
| retomada a partir dos artefatos que já existem.
*/

const IDEMPOTENCY_CERT_PASS_ENV = 'IDEMPOTENCY_TEST_CERT_PASS';

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    Notification::fake();

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('app.url', 'https://assinavelox.test');
    finalizationDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
});

afterEach(function () {
    putenv(IDEMPOTENCY_CERT_PASS_ENV);
    PdfFixtures::cleanup($this->work ?? null);
});

it('o job repetido não duplica versões nem conclui duas vezes', function () {
    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    finalizationRun($envelope);

    $envelope->refresh();
    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
    $finalId = (int) $envelope->final_document_version_id;
    $completedAt = $envelope->completed_at?->toIso8601String();

    // Três execuções extras: retentativa do worker, worker duplicado, reprocesso manual.
    finalizationRun($envelope);
    finalizationRun($envelope);
    finalizationRun($envelope);

    $envelope->refresh();

    $counts = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->get()
        ->countBy(fn (DocumentVersion $version): string => $version->kind->value);

    expect($counts['original'])->toBe(1)
        ->and($counts['consolidated'])->toBe(1)
        ->and($counts['evidence'])->toBe(1)
        ->and($counts['final'])->toBe(1)
        ->and((int) $envelope->final_document_version_id)->toBe($finalId)
        ->and($envelope->completed_at?->toIso8601String())->toBe($completedAt)
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->count())->toBe(1)
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail()->final_sha256)
        ->toBe($record->final_sha256);

    // Um único `envelope.completed` na trilha — a conclusão aconteceu uma vez só.
    $completedEvents = AuditEvent::query()
        ->where('envelope_id', $envelope->getKey())
        ->where('event_type', AuditEventType::EnvelopeCompleted->value)
        ->count();

    expect($completedEvents)->toBe(1);

    // E uma notificação de conclusão por destinatário, não quatro.
    Notification::assertSentTimes(EnvelopeCompletedNotification::class, 2);
});

it('retoma de uma queda entre a consolidação e a página de evidências', function () {
    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    // Simula a queda: só a etapa (a) chegou a acontecer, e o processo morreu.
    $finalizer = app(EnvelopeFinalizer::class);
    $partial = (new ReflectionClass($finalizer))->getMethod('consolidate');
    $partial->setAccessible(true);

    $workDir = TemporaryDirectory::create(app(PdfToolClient::class)->temporaryRoot(), 'parcial-');

    try {
        $consolidated = $partial->invoke(
            $finalizer,
            $envelope,
            $scenario['document'],
            $scenario['version'],
            $workDir,
            'correlacao-parcial',
        );
    } finally {
        $workDir->delete();
    }

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Finalizing);

    // Retomada: o job completo roda em cima do que já existe.
    finalizationRun($envelope);

    $envelope->refresh();

    $versions = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->get();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($versions->where('kind', DocumentVersionKind::Consolidated))->toHaveCount(1)
        // A MESMA versão consolidada: não foi recomposta nem duplicada.
        ->and((int) $versions->firstWhere('kind', DocumentVersionKind::Consolidated)->getKey())
        ->toBe((int) $consolidated->getKey())
        ->and($versions->where('kind', DocumentVersionKind::Evidence))->toHaveCount(1)
        ->and($versions->where('kind', DocumentVersionKind::Final))->toHaveCount(1);

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

    expect($record->consolidated_sha256)->toBe($consolidated->sha256);

    // Só um `envelope.consolidated` na trilha: a etapa reaproveitada não emite evento novo.
    expect(AuditEvent::query()
        ->where('envelope_id', $envelope->getKey())
        ->where('event_type', AuditEventType::EnvelopeConsolidated->value)
        ->count())->toBe(1);
});

it('regera o artefato quando o arquivo sumiu do disco mas a linha ficou no banco', function () {
    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    $finalizer = app(EnvelopeFinalizer::class);
    $consolidate = (new ReflectionClass($finalizer))->getMethod('consolidate');
    $consolidate->setAccessible(true);

    $workDir = TemporaryDirectory::create(app(PdfToolClient::class)->temporaryRoot(), 'parcial-');

    try {
        $consolidated = $consolidate->invoke($finalizer, $envelope, $scenario['document'], $scenario['version'], $workDir, 'cid');
    } finally {
        $workDir->delete();
    }

    // Bytes perdidos (disco trocado, arquivo apagado): a linha aponta para o nada.
    Storage::disk('documents')->delete($consolidated->storage_path);

    finalizationRun($envelope);

    $envelope->refresh();

    $consolidatedVersions = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->where('kind', DocumentVersionKind::Consolidated->value)
        ->get();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        // Uma nova versão consolidada foi gerada; a antiga (sem bytes) não é reaproveitada.
        ->and($consolidatedVersions)->toHaveCount(2);

    $usable = $consolidatedVersions->sortByDesc('version_number')->first();

    expect(Storage::disk('documents')->exists($usable->storage_path))->toBeTrue();

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

    expect($record->consolidated_sha256)->toBe($usable->sha256);
});

it('retoma quando o arquivo final já existe mas o banco não foi atualizado', function () {
    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    finalizationRun($envelope);
    $envelope->refresh();

    $finalVersion = $envelope->finalVersion;
    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
    $finalSha = $record->final_sha256;

    // Reproduz a queda "arquivo gravado, banco não atualizado": os artefatos continuam no
    // disco e no banco, mas o envelope volta a `finalizing` sem registro de verificação.
    $record->delete();
    $envelope->forceFill([
        'status' => EnvelopeStatus::Finalizing,
        'completed_at' => null,
        'final_document_version_id' => null,
    ])->save();

    $settings = $envelope->settings ?? [];
    unset($settings['completion_notified_at']);
    $envelope->forceFill(['settings' => $settings])->save();

    finalizationRun($envelope->refresh());

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        // O MESMO arquivo final: nada foi recomposto, reassinado ou duplicado.
        ->and((int) $envelope->final_document_version_id)->toBe((int) $finalVersion->getKey())
        ->and(DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $scenario['document']->getKey())
            ->where('kind', DocumentVersionKind::Final->value)
            ->count())->toBe(1);

    $recovered = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

    expect($recovered->final_sha256)->toBe($finalSha)
        ->and($recovered->final_sha256)->toBe(
            hash_file('sha256', finalizationDownload($envelope->finalVersion, $this->work.'/retomado.pdf')),
        );
});

it('refaz o arquivo final quando ele ficou incompatível com a configuração de assinatura', function () {
    // Primeira execução SEM certificado: o final sai sem assinatura.
    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    finalizationRun($envelope);
    $envelope->refresh();

    $unsignedFinal = $envelope->finalVersion;
    expect($unsignedFinal->has_signatures)->toBeFalse();

    // O envelope volta a `finalizing` (queda antes de gravar o registro) e agora o
    // certificado está configurado: o arquivo anterior descreve outra finalização.
    VerificationRecord::query()->where('envelope_id', $envelope->getKey())->delete();
    $envelope->forceFill([
        'status' => EnvelopeStatus::Finalizing,
        'completed_at' => null,
        'final_document_version_id' => null,
        'settings' => [],
    ])->save();

    putenv(IDEMPOTENCY_CERT_PASS_ENV.'=senha-de-teste-Xk93!');
    $certificate = TestCertificate::generate($this->work.DIRECTORY_SEPARATOR.'certs', IDEMPOTENCY_CERT_PASS_ENV, TestCertificate::SUBJECT, 2);
    TestCertificate::configure($certificate);

    finalizationRun($envelope->refresh());

    $envelope->refresh();

    $finals = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->where('kind', DocumentVersionKind::Final->value)
        ->get();

    // O final anterior (sem assinatura, nunca publicado) foi descartado, não empilhado.
    expect($finals)->toHaveCount(1)
        ->and((int) $finals->first()->getKey())->not->toBe((int) $unsignedFinal->getKey())
        ->and(Storage::disk('documents')->exists($unsignedFinal->storage_path))->toBeFalse();

    $path = finalizationDownload($envelope->finalVersion, $this->work.'/refeito.pdf');

    expect(app(PdfToolClient::class)->inspect($path)->signatureCount)->toBe(1)
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail()->final_sha256)
        ->toBe(hash_file('sha256', $path));
});

it('o job declara fila, unicidade por envelope e retentativa com backoff', function () {
    $job = new FinalizeEnvelope(10, 3, 'chave', 'cid');

    expect($job->queue)->toBe(config('assinavelox.queues.finalization'))
        ->and($job->uniqueId())->toBe('envelope-finalization:10')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120, 600])
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class);
});

it('registra a falha definitiva na trilha sem tirar o envelope de finalizing', function () {
    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    $job = new FinalizeEnvelope((int) $envelope->getKey(), (int) $envelope->organization_id, $envelope->finalization_key, 'cid');
    $job->failed(new RuntimeException('pdftool indisponível'));

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Finalizing)
        ->and(AuditEvent::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('event_type', AuditEventType::EnvelopeFinalizationFailed->value)
            ->exists())->toBeTrue();
});
