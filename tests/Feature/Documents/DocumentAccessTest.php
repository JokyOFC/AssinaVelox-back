<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    actingAsMember($owner, $organization);
});

afterEach(function () {
    cleanupDocumentsWorkspace($this->work ?? null);
});

/**
 * Anexa uma versão de um tipo qualquer ao documento, com bytes reais no disco falso.
 */
function attachVersion(Document $document, DocumentVersionKind $kind, string $contents): DocumentVersion
{
    $ulid = (string) Str::ulid();
    $path = sprintf('orgs/%s/envelopes/%s/%s.pdf', $document->organization->ulid, $document->envelope->ulid, $ulid);
    Storage::disk('documents')->put($path, $contents);

    $version = new DocumentVersion;
    $version->forceFill([
        'ulid' => $ulid,
        'document_id' => $document->getKey(),
        'organization_id' => $document->organization_id,
        'version_number' => $document->nextVersionNumber(),
        'kind' => $kind->value,
        'storage_disk' => 'documents',
        'storage_path' => $path,
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($contents),
        'sha256' => hash('sha256', $contents),
        'page_count' => 1,
    ]);
    $version->save();

    return $version;
}

describe('preview do PDF exibível', function () {
    it('transmite application/pdf inline, sem cache, para quem pode ver o envelope', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/contrato.pdf'), 'contrato.pdf')
            ->assertSessionHasNoErrors();

        $version = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail()->currentVersion;

        $response = $this->get(route('envelopes.document.preview', ['envelope' => $this->envelope->ulid]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Cache-Control'))->toContain('private');

        $body = $response->streamedContent();
        expect($body)->toStartWith('%PDF-')
            ->and(hash('sha256', $body))->toBe($version->sha256);
    });

    it('devolve 404 enquanto não há versão exibível (documento bloqueado)', function () {
        uploadDocument($this->envelope, PdfFixtures::encryptedPdf($this->work.'/protegido.pdf'), 'protegido.pdf')
            ->assertSessionHasNoErrors();

        expect(Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail()->processing_status)
            ->toBe(DocumentProcessingStatus::Blocked);

        $this->get(route('envelopes.document.preview', ['envelope' => $this->envelope->ulid]))->assertNotFound();
    });

    it('devolve 404 quando o envelope não tem documento', function () {
        $this->get(route('envelopes.document.preview', ['envelope' => $this->envelope->ulid]))->assertNotFound();
    });

    it('nega o preview a membro que não criou o envelope', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/contrato.pdf'), 'contrato.pdf')
            ->assertSessionHasNoErrors();

        $member = attachMember($this->organization, MembershipRole::Member);
        actingAsMember($member, $this->organization);

        $this->get(route('envelopes.document.preview', ['envelope' => $this->envelope->ulid]))->assertForbidden();
    });

    it('devolve 404 para envelope de outra organização', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/contrato.pdf'), 'contrato.pdf')
            ->assertSessionHasNoErrors();

        ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner(['name' => 'Vega']);
        actingAsMember($otherOwner, $other);

        $this->get(route('envelopes.document.preview', ['envelope' => $this->envelope->ulid]))->assertNotFound();
    });
});

describe('status do processamento', function () {
    it('devolve o shape usado pelo polling do wizard', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/contrato.pdf'), 'contrato.pdf')
            ->assertSessionHasNoErrors();

        $this->getJson(route('envelopes.document.status', ['envelope' => $this->envelope->ulid]))
            ->assertOk()
            ->assertJson([
                'status' => 'ready',
                'label' => 'Pronto',
                'pages' => 1,
                'error' => null,
                'failure_code' => null,
                'ready' => true,
                'terminal' => true,
                'progress_pct' => null,
                'envelope_status' => 'draft',
            ]);
    });

    it('expõe código e mensagem de falha em PT-BR quando o documento é bloqueado', function () {
        uploadDocument($this->envelope, PdfFixtures::encryptedPdf($this->work.'/protegido.pdf'), 'protegido.pdf')
            ->assertSessionHasNoErrors();

        $json = $this->getJson(route('envelopes.document.status', ['envelope' => $this->envelope->ulid]))
            ->assertOk()
            ->assertJson(['status' => 'blocked', 'ready' => false, 'terminal' => true, 'failure_code' => 'encrypted_pdf'])
            ->json();

        expect($json['error'])->toContain('protegido por senha');
    });

    it('devolve status nulo quando ainda não há arquivo', function () {
        $this->getJson(route('envelopes.document.status', ['envelope' => $this->envelope->ulid]))
            ->assertOk()
            ->assertJson(['status' => null, 'ready' => false, 'envelope_status' => 'draft']);
    });
});

describe('miniatura PNG', function () {
    it('responde 404: as miniaturas são renderizadas no navegador pelo PDF.js', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/contrato.pdf'), 'contrato.pdf')
            ->assertSessionHasNoErrors();

        $this->get(route('envelopes.document.page', ['envelope' => $this->envelope->ulid, 'page' => 1]))
            ->assertNotFound();
    });
});

describe('downloads', function () {
    it('baixa o original em qualquer status e registra envelope.downloaded', function () {
        $source = PdfFixtures::onePagePdf($this->work.'/contrato apto 302.pdf');
        $sha = hash_file('sha256', $source);

        uploadDocument($this->envelope, $source, 'contrato apto 302.pdf')->assertSessionHasNoErrors();

        $response = $this->get(route('envelopes.download', ['envelope' => $this->envelope->ulid, 'type' => 'original']));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
            ->and($response->headers->get('Content-Disposition'))->toContain('contrato apto 302.pdf')
            ->and(hash('sha256', $response->streamedContent()))->toBe($sha);

        $event = AuditEvent::query()
            ->where('envelope_id', $this->envelope->id)
            ->where('event_type', AuditEventType::EnvelopeDownloaded->value)
            ->firstOrFail();

        expect($event->payload['type'])->toBe('original')
            ->and($event->payload['sha256'])->toBe($sha);
    });

    it('baixa o DOCX original exatamente como enviado, mesmo com a conversão falhando', function () {
        config()->set('pdftool.libreoffice.binary', null);
        $source = DocumentFixtures::docx($this->work.'/contrato.docx');
        $sha = hash_file('sha256', $source);

        uploadDocument($this->envelope, $source, 'contrato.docx')->assertSessionHasNoErrors();

        $response = $this->get(route('envelopes.download', ['envelope' => $this->envelope->ulid, 'type' => 'original']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        expect(hash('sha256', $response->streamedContent()))->toBe($sha)
            ->and($response->headers->get('Content-Disposition'))->toContain('contrato.docx');
    });

    it('devolve 404 para signed e evidence antes da conclusão', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/contrato.pdf'), 'contrato.pdf')
            ->assertSessionHasNoErrors();

        $this->get(route('envelopes.download', ['envelope' => $this->envelope->ulid, 'type' => 'signed']))->assertNotFound();
        $this->get(route('envelopes.download', ['envelope' => $this->envelope->ulid, 'type' => 'evidence']))->assertNotFound();

        expect(AuditEvent::query()->where('event_type', AuditEventType::EnvelopeDownloaded->value)->count())->toBe(0);
    });

    it('baixa signed e evidence quando o envelope está concluído e as versões existem', function () {
        $envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->completed()->create();
        $document = Document::factory()->forEnvelope($envelope)->ready(1)->create();

        $final = attachVersion($document, DocumentVersionKind::Final, '%PDF-1.7 final');
        $evidence = attachVersion($document, DocumentVersionKind::Evidence, '%PDF-1.7 evidencias');
        $envelope->forceFill(['final_document_version_id' => $final->id])->save();

        $signed = $this->get(route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'signed']));
        $signed->assertOk();
        expect($signed->streamedContent())->toBe('%PDF-1.7 final')
            ->and($signed->headers->get('Content-Disposition'))->toContain($envelope->display_code.'-assinado.pdf');

        $report = $this->get(route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'evidence']));
        $report->assertOk();
        expect($report->streamedContent())->toBe('%PDF-1.7 evidencias')
            ->and($report->headers->get('Content-Disposition'))->toContain($envelope->display_code.'-evidencias.pdf');

        expect($evidence->kind)->toBe(DocumentVersionKind::Evidence)
            ->and(AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', AuditEventType::EnvelopeDownloaded->value)->count())
            ->toBe(2);
    });

    it('devolve 404 quando o envelope está concluído mas a versão não existe', function () {
        $envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->completed()->create();
        Document::factory()->forEnvelope($envelope)->ready(1)->create();

        $this->get(route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'signed']))->assertNotFound();
    });

    it('devolve 404 quando o arquivo sumiu do disco', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/contrato.pdf'), 'contrato.pdf')
            ->assertSessionHasNoErrors();

        $version = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail()->versions()->firstOrFail();
        Storage::disk('documents')->delete($version->storage_path);

        $this->get(route('envelopes.download', ['envelope' => $this->envelope->ulid, 'type' => 'original']))->assertNotFound();
    });

    it('nunca serve arquivo de outra organização', function () {
        uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/contrato.pdf'), 'contrato.pdf')
            ->assertSessionHasNoErrors();

        ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner(['name' => 'Vega']);
        actingAsMember($otherOwner, $other);

        $this->get(route('envelopes.download', ['envelope' => $this->envelope->ulid, 'type' => 'original']))->assertNotFound();

        expect(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::EnvelopeDownloaded->value)->count())->toBe(0);
    });
});
