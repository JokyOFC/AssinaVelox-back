<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Models\AuditEvent;
use App\Models\VerificationRecord;
use App\Services\Envelopes\Finalization\EnvelopeFinalizer;
use App\Services\Verification\PublicVerification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Finalization/Support/FinalizationHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/Support/DomainHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.3 — finalização POR DOCUMENTO, idempotente e retomável
|--------------------------------------------------------------------------
| Roda contra o pdftool REAL. Cada documento vira consolidado + página de evidências +
| final; o envelope só conclui com todos os finais; um resumo publicado por arquivo. Uma
| falha no meio deixa os artefatos já prontos, e a retentativa os reaproveita sem duplicar.
*/

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    Notification::fake();

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('pdftool.company_certificate.pfx_path', null);
    config()->set('app.url', 'https://assinavelox.test');
    finalizationDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
});

afterEach(function () {
    PdfFixtures::cleanup($this->work ?? null);
});

function multiEvidenceText(string $path): string
{
    $text = collect(finalizationPdfPages($path))
        ->flatMap(fn (array $page): array => array_map(fn (array $run): string => (string) $run['text'], $page['runs']))
        ->implode(' ');

    return (string) preg_replace('/\s+/u', ' ', $text);
}

it('finaliza cada documento, publica um resumo por arquivo e conclui só com todos os finais', function () {
    $scenario = domainFinalizingEnvelope($this->work);
    $envelope = $scenario['envelope'];

    finalizationRun($envelope);

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
    $children = $record->documents()->get();

    expect($children)->toHaveCount(3)
        ->and($children->pluck('position')->all())->toBe([1, 2, 3]);

    foreach ($scenario['documents'] as $index => $document) {
        $document->refresh();

        foreach ([DocumentVersionKind::Consolidated, DocumentVersionKind::Evidence, DocumentVersionKind::Final] as $kind) {
            expect(domainVersionsOfKind($document, $kind))->toHaveCount(1);
        }

        $final = $document->finalVersion;
        $bytes = (string) Storage::disk('documents')->get($final->storage_path);

        expect(hash('sha256', $bytes))->toBe($children[$index]->final_sha256)
            ->and($children[$index]->sent_sha256)->toBe($scenario['versions'][$index]->sha256);

        // Um byte trocado e o resumo não confere mais.
        $tampered = $bytes;
        $tampered[150] = chr(ord($tampered[150]) ^ 0x01);

        expect(app(PublicVerification::class)->checkHash($envelope, hash('sha256', $tampered))['matches'])->toBe('none')
            ->and(app(PublicVerification::class)->checkHash($envelope, hash('sha256', $bytes))['document']['position'])->toBe($index + 1);
    }

    // Colunas da Fase 1 = primeiro documento.
    expect((int) $envelope->final_document_version_id)->toBe((int) $scenario['documents'][0]->fresh()->final_version_id)
        ->and($record->final_sha256)->toBe($children[0]->final_sha256)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::DocumentFinalized->value)->count())->toBe(3);

    $completed = AuditEvent::query()->where('event_type', AuditEventType::EnvelopeCompleted->value)->sole();
    expect($completed->payload['documents'])->toHaveCount(3);

    // A página de evidências do arquivo 2 lista todos os documentos e diz qual é ela.
    $evidence = domainVersionsOfKind($scenario['documents'][1]->fresh(), DocumentVersionKind::Evidence)->first();
    $text = multiEvidenceText(finalizationDownload($evidence, $this->work.'/evidencias-2.pdf'));

    expect($text)->toContain('Documentos deste envelope')
        ->and($text)->toContain('2 de 3')
        ->and($text)->toContain('Maria Alves Souza');
});

it('retoma documento a documento depois de uma falha no meio, sem duplicar versões', function () {
    $scenario = domainFinalizingEnvelope($this->work);
    $envelope = $scenario['envelope'];

    // O terceiro arquivo congelado fica ilegível: a consolidação dele falha depois de os dois
    // primeiros já estarem prontos.
    $third = $scenario['versions'][2];
    $original = (string) Storage::disk('documents')->get($third->storage_path);
    Storage::disk('documents')->put($third->storage_path, "%PDF-1.7\n% corrompido\n");

    $failed = false;

    try {
        finalizationRun($envelope);
    } catch (Throwable) {
        $failed = true;
    }

    expect($failed)->toBeTrue()
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Finalizing)
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->exists())->toBeFalse()
        ->and(domainVersionsOfKind($scenario['documents'][0], DocumentVersionKind::Consolidated))->toHaveCount(1)
        ->and(domainVersionsOfKind($scenario['documents'][1], DocumentVersionKind::Consolidated))->toHaveCount(1)
        ->and(domainVersionsOfKind($scenario['documents'][2], DocumentVersionKind::Consolidated))->toHaveCount(0);

    foreach ($scenario['documents'] as $document) {
        expect($document->fresh()->final_version_id)->toBeNull();
    }

    Storage::disk('documents')->put($third->storage_path, $original);

    $outcome = app(EnvelopeFinalizer::class)->handle((int) $envelope->getKey(), (int) $envelope->organization_id);

    expect($outcome->status)->toBe('completed')
        ->and($outcome->steps['documents.1.consolidated'])->toBe('reused')
        ->and($outcome->steps['documents.2.consolidated'])->toBe('reused')
        ->and($outcome->steps['documents.3.consolidated'])->toBe('created')
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Completed);

    foreach ($scenario['documents'] as $document) {
        foreach ([DocumentVersionKind::Consolidated, DocumentVersionKind::Evidence, DocumentVersionKind::Final] as $kind) {
            expect(domainVersionsOfKind($document, $kind))->toHaveCount(1);
        }
    }

    // Uma terceira execução não faz nada.
    $again = app(EnvelopeFinalizer::class)->handle((int) $envelope->getKey(), (int) $envelope->organization_id);

    expect($again->status)->toBe('already_completed')
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->sole()->documents()->count())->toBe(3);
});
