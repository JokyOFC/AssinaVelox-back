<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Integrations\Pdf\LibreOfficeConverter;
use App\Jobs\Documents\ProcessDocumentUpload;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Documents\EnvelopeReadiness;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Documents\Support\DocumentFixtures;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/helpers.php';

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->owner = $owner;
    $this->envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();
    $this->client = app(PdfToolClient::class);

    actingAsMember($owner, $organization);
});

afterEach(function () {
    cleanupDocumentsWorkspace($this->work ?? null);
});

describe('PDF que não pode ser preparado', function () {
    it('bloqueia PDF protegido por senha e preserva o original', function () {
        $source = PdfFixtures::encryptedPdf($this->work.'/protegido.pdf');
        $sha = hash_file('sha256', $source);

        uploadDocument($this->envelope, $source, 'protegido.pdf')->assertSessionHasNoErrors();

        $document = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();

        expect($document->processing_status)->toBe(DocumentProcessingStatus::Blocked)
            ->and($document->failure_code)->toBe('encrypted_pdf')
            ->and($document->failure_message)->toContain('protegido por senha')
            ->and($document->current_version_id)->toBeNull();

        // Original preservado byte a byte; nenhuma versão nova foi criada.
        $versions = $document->versions()->get();
        expect($versions)->toHaveCount(1)
            ->and($versions[0]->kind)->toBe(DocumentVersionKind::Original)
            ->and($versions[0]->sha256)->toBe($sha)
            ->and($versions[0]->is_encrypted)->toBeTrue()
            ->and(hash('sha256', (string) Storage::disk('documents')->get($versions[0]->storage_path)))->toBe($sha);

        expect($this->envelope->fresh()->status)->toBe(EnvelopeStatus::Draft)
            ->and(AuditEvent::query()->where('envelope_id', $this->envelope->id)->pluck('event_type')->all())
            ->toContain(AuditEventType::DocumentBlocked);
    });

    it('bloqueia PDF já assinado digitalmente, explicando que preparar destruiria as assinaturas', function () {
        $source = DocumentFixtures::signedPdf($this->work, $this->client);
        $sha = hash_file('sha256', $source);

        expect($this->client->inspect($source)->hasSignatures)->toBeTrue();

        uploadDocument($this->envelope, $source, 'contrato-assinado.pdf')->assertSessionHasNoErrors();

        $document = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();

        expect($document->processing_status)->toBe(DocumentProcessingStatus::Blocked)
            ->and($document->failure_code)->toBe('has_signatures')
            ->and($document->failure_message)->toContain('já contém assinaturas digitais')
            ->and($document->failure_message)->toContain('invalidaria');

        $original = $document->versions()->firstOrFail();
        expect($document->versions()->count())->toBe(1)
            ->and($original->has_signatures)->toBeTrue()
            ->and($original->sha256)->toBe($sha)
            ->and(hash('sha256', (string) Storage::disk('documents')->get($original->storage_path)))->toBe($sha);

        expect($this->envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);
    });

    it('bloqueia PDF corrompido', function () {
        $source = PdfFixtures::corruptedPdf($this->work.'/corrompido.pdf');

        uploadDocument($this->envelope, $source, 'corrompido.pdf')->assertSessionHasNoErrors();

        $document = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();

        expect($document->processing_status)->toBe(DocumentProcessingStatus::Blocked)
            ->and($document->failure_code)->toBe('invalid_pdf')
            ->and($document->current_version_id)->toBeNull()
            ->and($document->versions()->count())->toBe(1);
    });
});

describe('DOCX', function () {
    it('falha de forma honesta quando o LibreOffice não está configurado', function () {
        config()->set('pdftool.libreoffice.binary', null);
        expect(app(LibreOfficeConverter::class)->isConfigured())->toBeFalse();

        $source = DocumentFixtures::docx($this->work.'/contrato.docx');

        uploadDocument($this->envelope, $source, 'contrato.docx')->assertSessionHasNoErrors();

        $document = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();

        expect($document->processing_status)->toBe(DocumentProcessingStatus::Failed)
            ->and($document->failure_code)->toBe('converter_not_configured')
            ->and($document->failure_message)->toBe('A conversão de arquivos DOCX não está disponível nesta instalação. Converta o documento para PDF e envie novamente.')
            ->and($document->current_version_id)->toBeNull()
            // NADA foi convertido: só existe o DOCX original.
            ->and($document->versions()->count())->toBe(1)
            ->and($document->versions()->first()->mime_type)
            ->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        expect($this->envelope->fresh()->status)->toBe(EnvelopeStatus::Draft)
            ->and(AuditEvent::query()->where('envelope_id', $this->envelope->id)->pluck('event_type')->all())
            ->toContain(AuditEventType::DocumentProcessingFailed);
    });

    it('converte com o binário falso do LibreOffice e cria a versão convertida', function () {
        config()->set('pdftool.libreoffice.binary', PdfFixtures::fakeSoffice());
        config()->set('pdftool.libreoffice.env', [
            'FAKE_SOFFICE_PDF' => PdfFixtures::fakeConvertedPdf(),
        ]);

        $source = DocumentFixtures::docx($this->work.'/contrato.docx');
        $originalSha = hash_file('sha256', $source);

        uploadDocument($this->envelope, $source, 'contrato.docx')->assertSessionHasNoErrors();

        $document = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();

        expect($document->processing_status)->toBe(DocumentProcessingStatus::Ready)
            ->and($document->page_count)->toBe(1);

        $original = $document->versions()->where('kind', DocumentVersionKind::Original->value)->firstOrFail();
        $converted = $document->versions()->where('kind', DocumentVersionKind::Converted->value)->firstOrFail();

        expect($original->sha256)->toBe($originalSha)
            ->and($converted->mime_type)->toBe('application/pdf')
            ->and($converted->sha256)->toBe(hash_file('sha256', PdfFixtures::fakeConvertedPdf()))
            ->and($document->current_version_id)->toBe($converted->id);
    });
});

describe('pages_meta', function () {
    it('grava exatamente o que o inspect devolve, inclusive em página rotacionada', function () {
        $source = DocumentFixtures::rotatedPdf($this->work.'/rotacionado.pdf', $this->client);
        $inspection = $this->client->inspect($source);

        expect($inspection->pageCount)->toBe(2)
            ->and($inspection->page(2)?->rotation)->toBe(90);

        uploadDocument($this->envelope, $source, 'rotacionado.pdf')->assertSessionHasNoErrors();

        $version = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail()->currentVersion;

        expect($version)->not->toBeNull()
            ->and($version->page_count)->toBe(2)
            ->and($version->pages_meta)->toEqual($inspection->pagesMeta());

        // Na página girada 90°, o inspect (e portanto pages_meta) traz as dimensões EXIBIDAS.
        $rotated = $version->pageMeta(2);
        expect($rotated['rotation'])->toBe(90)
            ->and($rotated['width_pt'])->toBeGreaterThan($rotated['height_pt'])
            ->and($rotated['width_pt'])->toEqualWithDelta($inspection->page(2)->widthPt, 0.001)
            ->and($rotated['mediabox'])->toHaveCount(4)
            ->and($rotated['cropbox'])->toHaveCount(4);
    });
});

describe('idempotência', function () {
    it('reprocessar o mesmo documento não duplica versões nem arquivos', function () {
        uploadDocument($this->envelope, PdfFixtures::jpeg($this->work.'/foto.jpg'), 'foto.jpg')->assertSessionHasNoErrors();

        $document = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();
        $versionIds = $document->versions()->pluck('id')->all();
        $files = Storage::disk('documents')->allFiles();
        $converted = $document->currentVersion;

        expect($versionIds)->toHaveCount(2);

        // Reexecuta o job (worker que repetiu a mensagem).
        app()->call([new ProcessDocumentUpload($document->id, $this->organization->id), 'handle']);

        $document->refresh();

        expect($document->versions()->pluck('id')->all())->toBe($versionIds)
            ->and($document->current_version_id)->toBe($converted->id)
            ->and($document->processing_status)->toBe(DocumentProcessingStatus::Ready)
            ->and(Storage::disk('documents')->allFiles())->toBe($files);
    });

    it('reprocessar um documento com conversão pendente reaproveita a versão convertida existente', function () {
        uploadDocument($this->envelope, PdfFixtures::jpeg($this->work.'/foto.jpg'), 'foto.jpg')->assertSessionHasNoErrors();

        $document = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();
        $convertedId = $document->current_version_id;

        // Simula um worker que morreu logo depois de gravar a versão convertida.
        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::Converting->value,
            'current_version_id' => null,
        ])->save();

        app()->call([new ProcessDocumentUpload($document->id, $this->organization->id), 'handle']);

        $document->refresh();

        expect($document->versions()->count())->toBe(2)
            ->and($document->current_version_id)->toBe($convertedId)
            ->and($document->processing_status)->toBe(DocumentProcessingStatus::Ready);
    });

    it('ignora documento que já não existe', function () {
        app()->call([new ProcessDocumentUpload(999_999, $this->organization->id), 'handle']);
    })->throwsNoExceptions();
});

describe('prontidão do envelope', function () {
    it('só marca ready quando há documento pronto, destinatário e campo de assinatura por destinatário', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/contrato.pdf'), 'contrato.pdf')
            ->assertSessionHasNoErrors();

        $envelope = $this->envelope->fresh();
        $readiness = app(EnvelopeReadiness::class);

        // Documento pronto, mas sem destinatários.
        expect($readiness->recompute($envelope))->toBe(EnvelopeStatus::Draft)
            ->and($readiness->completeness($envelope))->toBe(['document' => true, 'recipients' => false, 'fields' => false]);

        $recipient = Recipient::factory()->forEnvelope($envelope)->create();
        $envelope->unsetRelation('recipients');

        // Com destinatário, mas sem campo de assinatura.
        expect($readiness->recompute($envelope))->toBe(EnvelopeStatus::Draft)
            ->and($readiness->completeness($envelope)['fields'])->toBeFalse();

        SigningField::query()->create([
            'envelope_id' => $envelope->id,
            'document_version_id' => $envelope->document->current_version_id,
            'recipient_id' => $recipient->id,
            'organization_id' => $this->organization->id,
            'type' => FieldType::Signature,
            'page' => 1,
            'x' => 0.1,
            'y' => 0.8,
            'width' => 0.3,
            'height' => 0.06,
        ]);

        expect($readiness->recompute($envelope))->toBe(EnvelopeStatus::Ready)
            ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);

        // Um segundo destinatário sem campo derruba a prontidão.
        Recipient::factory()->forEnvelope($envelope, 2)->create();
        $envelope = $this->envelope->fresh();

        expect($readiness->recompute($envelope))->toBe(EnvelopeStatus::Draft);
    });

    it('não mexe em envelope já enviado', function () {
        $envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create();

        expect(app(EnvelopeReadiness::class)->recompute($envelope))->toBe(EnvelopeStatus::InProgress)
            ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress);
    });
});
