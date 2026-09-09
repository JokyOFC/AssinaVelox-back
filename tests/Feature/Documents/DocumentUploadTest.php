<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentSourceType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Jobs\Documents\ProcessDocumentUpload;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Documents\UploadInspector;
use Illuminate\Support\Facades\Queue;
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

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Imobiliária Horizonte']);
    $this->organization = $organization;
    $this->owner = $owner;
    $this->envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();

    actingAsMember($owner, $organization);
});

afterEach(function () {
    cleanupDocumentsWorkspace($this->work ?? null);
});

describe('upload aceito', function () {
    it('grava original + versão exibível, hash, evento e deixa o documento pronto', function () {
        $source = PdfFixtures::onePagePdf($this->work.'/contrato apto 302.pdf');
        $expectedSha = hash_file('sha256', $source);
        $expectedSize = filesize($source);

        uploadDocument($this->envelope, $source, 'contrato apto 302.pdf', 'application/pdf')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $document = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();

        expect($document->source_type)->toBe(DocumentSourceType::Pdf)
            ->and($document->original_filename)->toBe('contrato apto 302.pdf')
            ->and($document->name)->toBe('contrato apto 302')
            ->and($document->processing_status)->toBe(DocumentProcessingStatus::Ready)
            ->and($document->page_count)->toBe(1)
            ->and($document->failure_code)->toBeNull();

        // PDF válido: a versão original É a versão exibível — os bytes não são duplicados.
        $versions = $document->versions()->get();
        expect($versions)->toHaveCount(1)
            ->and($versions[0]->kind)->toBe(DocumentVersionKind::Original)
            ->and($document->current_version_id)->toBe($versions[0]->id);

        $original = $versions[0];
        expect($original->sha256)->toBe($expectedSha)
            ->and($original->size_bytes)->toBe($expectedSize)
            ->and($original->mime_type)->toBe('application/pdf')
            ->and($original->storage_disk)->toBe('documents')
            ->and($original->page_count)->toBe(1)
            ->and($original->is_encrypted)->toBeFalse()
            ->and($original->has_signatures)->toBeFalse();

        // Caminho por organização, montado só com identificadores opacos.
        expect($original->storage_path)
            ->toBe(sprintf('orgs/%s/envelopes/%s/%s.pdf', $this->organization->ulid, $this->envelope->ulid, $original->ulid))
            ->and($original->storage_path)->not->toContain('contrato');

        expect(Storage::disk('documents')->exists($original->storage_path))->toBeTrue()
            ->and(hash('sha256', (string) Storage::disk('documents')->get($original->storage_path)))->toBe($expectedSha);

        $events = AuditEvent::query()->where('envelope_id', $this->envelope->id)->pluck('event_type')->all();
        expect($events)->toContain(AuditEventType::DocumentUploaded)
            ->and($events)->toContain(AuditEventType::DocumentConversionStarted)
            ->and($events)->toContain(AuditEventType::DocumentConverted);

        $uploaded = AuditEvent::query()->where('event_type', AuditEventType::DocumentUploaded->value)->firstOrFail();
        expect($uploaded->payload['sha256'])->toBe($expectedSha)
            ->and($uploaded->payload['original_filename'])->toBe('contrato apto 302.pdf');

        // Sem destinatários e campos, o envelope volta a rascunho (não fica "pronto").
        expect($this->envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);
    });

    it('converte imagem em um PDF de uma página, mantendo o original intacto', function () {
        $source = PdfFixtures::jpeg($this->work.'/foto-documento.jpg', 900, 600);
        $originalSha = hash_file('sha256', $source);

        uploadDocument($this->envelope, $source, 'foto-documento.jpg', 'image/jpeg')->assertSessionHasNoErrors();

        $document = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();

        expect($document->source_type)->toBe(DocumentSourceType::Image)
            ->and($document->processing_status)->toBe(DocumentProcessingStatus::Ready)
            ->and($document->page_count)->toBe(1);

        $original = $document->versions()->where('kind', DocumentVersionKind::Original->value)->firstOrFail();
        $converted = $document->versions()->where('kind', DocumentVersionKind::Converted->value)->firstOrFail();

        expect($original->sha256)->toBe($originalSha)
            ->and($original->mime_type)->toBe('image/jpeg')
            ->and($converted->mime_type)->toBe('application/pdf')
            ->and($converted->version_number)->toBe(2)
            ->and($converted->page_count)->toBe(1)
            ->and($converted->sha256)->not->toBe($originalSha)
            ->and($document->current_version_id)->toBe($converted->id);

        // O original continua no disco, byte a byte.
        expect(hash('sha256', (string) Storage::disk('documents')->get($original->storage_path)))->toBe($originalSha)
            ->and(Storage::disk('documents')->exists($converted->storage_path))->toBeTrue();
    });

    it('aceita PNG', function () {
        PdfFixtures::signaturePng($this->work.'/pagina.png', 600, 400);

        uploadDocument($this->envelope, $this->work.'/pagina.png', 'pagina.png')->assertSessionHasNoErrors();

        expect(Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail()->processing_status)
            ->toBe(DocumentProcessingStatus::Ready);
    });

    it('aceita WEBP', function () {
        $image = imagecreatetruecolor(600, 400);
        imagefilledrectangle($image, 0, 0, 599, 399, (int) imagecolorallocate($image, 255, 255, 255));
        imagewebp($image, $this->work.'/pagina.webp');
        imagedestroy($image);

        uploadDocument($this->envelope, $this->work.'/pagina.webp', 'pagina.webp')->assertSessionHasNoErrors();

        expect(Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail()->processing_status)
            ->toBe(DocumentProcessingStatus::Ready);
    });

    it('substitui o documento anterior, apaga os campos e volta o envelope para rascunho', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/primeiro.pdf'), 'primeiro.pdf')
            ->assertSessionHasNoErrors();

        $oldDocument = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();
        $oldVersion = $oldDocument->versions()->firstOrFail();
        $oldPath = $oldVersion->storage_path;

        $recipient = Recipient::factory()->forEnvelope($this->envelope)->create();
        SigningField::query()->create([
            'envelope_id' => $this->envelope->id,
            'document_version_id' => $oldVersion->id,
            'recipient_id' => $recipient->id,
            'organization_id' => $this->organization->id,
            'type' => FieldType::Signature,
            'page' => 1,
            'x' => 0.1,
            'y' => 0.1,
            'width' => 0.2,
            'height' => 0.05,
        ]);

        Envelope::withoutOrganizationScope()->whereKey($this->envelope->id)->update(['status' => EnvelopeStatus::Ready->value]);

        uploadDocument($this->envelope->fresh(), PdfFixtures::twoPagePdf($this->work.'/segundo.pdf'), 'segundo.pdf')
            ->assertSessionHasNoErrors();

        expect(Document::query()->where('envelope_id', $this->envelope->id)->count())->toBe(1)
            ->and(Document::withoutOrganizationScope()->whereKey($oldDocument->id)->exists())->toBeFalse()
            ->and(DocumentVersion::withoutOrganizationScope()->whereKey($oldVersion->id)->exists())->toBeFalse()
            ->and(Storage::disk('documents')->exists($oldPath))->toBeFalse()
            ->and(SigningField::query()->where('envelope_id', $this->envelope->id)->count())->toBe(0);

        expect(Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail()->page_count)->toBe(2);

        expect(AuditEvent::query()->where('envelope_id', $this->envelope->id)->pluck('event_type')->all())
            ->toContain(AuditEventType::DocumentRemoved);
    });

    it('remove o documento e devolve o envelope para rascunho', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/remover.pdf'), 'remover.pdf')
            ->assertSessionHasNoErrors();

        $version = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail()->versions()->firstOrFail();

        $this->delete(route('envelopes.document.destroy', ['envelope' => $this->envelope->ulid]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(Document::query()->where('envelope_id', $this->envelope->id)->exists())->toBeFalse()
            ->and(Storage::disk('documents')->exists($version->storage_path))->toBeFalse()
            ->and($this->envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);
    });
});

describe('upload recusado (validação sobre o conteúdo)', function () {
    beforeEach(function () {
        // A recusa acontece antes de qualquer processamento: nada vai para a fila.
        Queue::fake();
    });

    it('recusa SVG', function () {
        uploadDocument($this->envelope, PdfFixtures::svg($this->work.'/logo.svg'), 'logo.svg', 'image/svg+xml')
            ->assertSessionHasErrors(['file' => 'Formato não aceito. Envie um arquivo PDF, DOCX, PNG, JPEG ou WEBP.']);

        expectNothingPersisted($this->envelope);
    });

    it('recusa SVG mesmo renomeado como .png com MIME de imagem', function () {
        copy(PdfFixtures::svg($this->work.'/disfarce.svg'), $this->work.'/disfarce.png');

        uploadDocument($this->envelope, $this->work.'/disfarce.png', 'disfarce.png', 'image/png')
            ->assertSessionHasErrors('file');

        expectNothingPersisted($this->envelope);
    });

    it('recusa arquivo com extensão .pdf e conteúdo de outra coisa', function () {
        $png = PdfFixtures::signaturePng($this->work.'/na-verdade-png.bin', 200, 100);

        uploadDocument($this->envelope, $png, 'contrato.pdf', 'application/pdf')->assertSessionHasErrors('file');

        expect(session('errors')->first('file'))->toContain('não corresponde à extensão informada');
        expectNothingPersisted($this->envelope);
    });

    it('recusa executável renomeado como PDF', function () {
        file_put_contents($this->work.'/malware.pdf', "MZ\x90\x00".str_repeat("\x00", 512));

        uploadDocument($this->envelope, $this->work.'/malware.pdf', 'malware.pdf', 'application/pdf')
            ->assertSessionHasErrors('file');

        expectNothingPersisted($this->envelope);
    });

    it('recusa DOCX com bomba de descompressão', function () {
        $bomb = DocumentFixtures::docxZipBomb($this->work.'/bomba.docx');

        // Poucos KB no disco, dezenas de MB descompactados.
        expect(filesize($bomb))->toBeLessThan(1024 * 1024);

        uploadDocument($this->envelope, $bomb, 'bomba.docx')->assertSessionHasErrors('file');

        expect(session('errors')->first('file'))->toContain('taxa de compressão suspeita');
        expectNothingPersisted($this->envelope);
    });

    it('recusa ZIP que não é documento do Word', function () {
        uploadDocument($this->envelope, DocumentFixtures::zipWithoutWordDocument($this->work.'/planilha.docx'), 'planilha.docx')
            ->assertSessionHasErrors(['file' => 'O arquivo não é um documento do Word (.docx) válido.']);

        expectNothingPersisted($this->envelope);
    });

    it('recusa imagem acima do limite de megapixels sem decodificar os pixels', function () {
        // 8000 × 8000 = 64 MP; só o cabeçalho é lido (o arquivo tem alguns bytes).
        $huge = PdfFixtures::pngHeaderOnly($this->work.'/gigante.png', 8000, 8000);
        expect(filesize($huge))->toBeLessThan(1024);

        uploadDocument($this->envelope, $huge, 'gigante.png')->assertSessionHasErrors('file');

        expect(session('errors')->first('file'))->toContain('megapixels');
        expectNothingPersisted($this->envelope);
    });

    it('recusa arquivo acima do limite de tamanho', function () {
        config()->set('assinavelox.upload.max_mb', 1);
        file_put_contents($this->work.'/grande.pdf', '%PDF-1.7'.str_repeat('A', 2 * 1024 * 1024));

        uploadDocument($this->envelope, $this->work.'/grande.pdf', 'grande.pdf')->assertSessionHasErrors('file');

        expectNothingPersisted($this->envelope);
    });

    it('recusa arquivo vazio', function () {
        file_put_contents($this->work.'/vazio.pdf', '');

        uploadDocument($this->envelope, $this->work.'/vazio.pdf', 'vazio.pdf')->assertSessionHasErrors('file');

        expectNothingPersisted($this->envelope);
    });

    it('recusa upload em envelope já enviado', function () {
        $envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create();

        uploadDocument($envelope, PdfFixtures::onePagePdf($this->work.'/tarde-demais.pdf'), 'tarde-demais.pdf')
            ->assertSessionHas('error', 'Ação indisponível no status atual.');

        expect(Document::query()->where('envelope_id', $envelope->id)->exists())->toBeFalse();
        Queue::assertNothingPushed();
    });

    it('devolve 404 ao enviar arquivo para envelope de outra organização', function () {
        ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner(['name' => 'Vega']);
        $foreign = Envelope::factory()->forOrganization($other, $otherOwner)->draft()->create();

        uploadDocument($foreign, PdfFixtures::onePagePdf($this->work.'/alheio.pdf'), 'alheio.pdf')->assertNotFound();

        expect(Document::withoutOrganizationScope()->count())->toBe(0);
        Queue::assertNothingPushed();
    });
});

describe('despacho do processamento', function () {
    it('enfileira ProcessDocumentUpload na fila conversions com a organização do envelope', function () {
        Queue::fake();

        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/enfileira.pdf'), 'enfileira.pdf')
            ->assertSessionHasNoErrors();

        $document = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();

        expect($document->processing_status)->toBe(DocumentProcessingStatus::Uploaded)
            ->and($this->envelope->fresh()->status)->toBe(EnvelopeStatus::Preparing);

        Queue::assertPushed(
            ProcessDocumentUpload::class,
            fn (ProcessDocumentUpload $job): bool => $job->documentId === $document->id
                && $job->organizationId === $this->organization->id
                && $job->queue === 'conversions',
        );
    });
});

it('sanitiza o nome exibido sem jamais usá-lo como caminho', function () {
    $inspector = app(UploadInspector::class);

    expect($inspector->sanitizeFilename('../../etc/passwd', 'pdf'))->toBe('passwd')
        ->and($inspector->sanitizeFilename('C:\\Users\\x\\contrato.pdf', 'pdf'))->toBe('contrato.pdf')
        ->and($inspector->sanitizeFilename("nome\ncom\tcontrole.pdf", 'pdf'))->toBe('nomecomcontrole.pdf')
        ->and($inspector->sanitizeFilename('   ', 'pdf'))->toBe('documento.pdf')
        ->and($inspector->sanitizeFilename('..', 'pdf'))->toBe('documento.pdf');
});
