<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial — integridade documental
|--------------------------------------------------------------------------
|
| DEFEITO: App\Services\Envelopes\DuplicateEnvelope::copyDocument() copia SEMPRE a
| versão `original` (`$document->originalVersion ?? $document->currentVersion`) e a
| grava como `documents.current_version_id` da cópia, mantendo
| `processing_status = ready`.
|
| Para um envelope cuja origem é DOCX ou IMAGEM, a versão `original` NÃO é um PDF —
| a versão exibível é a `converted`, produzida pelo pipeline. A cópia nasce, então,
| "pronta" apontando para bytes que não são PDF:
|
|  - `envelopes.document.preview` transmite esses bytes com `Content-Type: application/pdf`;
|  - a cópia atinge `ready` e pode ser ENVIADA;
|  - o envio congela `sent_document_version_id` na versão não-PDF, e é ela que
|    `sign.document` entrega ao signatário e cujo sha256 vai para
|    `signature_acceptances.document_sha256` e para a declaração de aceite;
|  - `pages_meta` da versão original é nulo (só a convertida recebe o inspect), então a
|    geometria dos campos passa a ser validada contra o retângulo A4 de fallback de
|    `FieldSync::pageBox()`, e não contra a página real.
|
| O teste existente (`EnvelopeWizardTest`, "duplicar cria um rascunho independente…")
| passa por acidente: `draftWithDocument()` cria UMA única versão, `kind = original` e
| `mime_type = application/pdf`, de modo que original e exibível coincidem e o caso
| DOCX/imagem nunca é exercido.
|
| Este teste percorre o pipeline REAL (upload de PNG → ImageToPdfConverter → pdftool).
*/

use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Documents/Support/helpers.php';

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
    $this->withoutVite();
});

afterEach(fn () => cleanupDocumentsWorkspace($this->work ?? null));

it('duplicar um envelope de origem imagem mantém a versão exibível em PDF, não os bytes originais', function () {
    // Origem: uma imagem. O pipeline gera a versão `converted` (PDF) e é ela a exibível.
    uploadDocument($this->envelope, PdfFixtures::jpeg($this->work.'/foto.jpg'), 'foto.jpg')
        ->assertSessionHasNoErrors();

    $source = Document::query()->where('envelope_id', $this->envelope->id)->firstOrFail();

    expect($source->processing_status)->toBe(DocumentProcessingStatus::Ready)
        ->and($source->currentVersion->kind)->toBe(DocumentVersionKind::Converted)
        ->and($source->currentVersion->mime_type)->toBe('application/pdf');

    $this->post(route('envelopes.duplicate', $this->envelope))->assertRedirect();

    $copy = Envelope::query()->where('id', '!=', $this->envelope->id)->latest('id')->firstOrFail();

    /** @var DocumentVersion $displayable */
    $displayable = DocumentVersion::query()->whereKey($copy->document->current_version_id)->firstOrFail();

    // A versão marcada como exibível na cópia precisa ser um PDF de verdade.
    expect($displayable->mime_type)->toBe('application/pdf');

    // …e os bytes no disco precisam começar com %PDF-.
    expect(substr((string) Storage::disk('documents')->get($displayable->storage_path), 0, 5))->toBe('%PDF-');

    // Sem pages_meta, FieldSync cai no A4 de fallback e a geometria deixa de valer.
    expect($displayable->pages_meta)->not->toBeNull();
});

it('a cópia não pode aparecer como "pronta" servindo bytes que não são PDF na pré-visualização', function () {
    uploadDocument($this->envelope, PdfFixtures::jpeg($this->work.'/foto.jpg'), 'foto.jpg')
        ->assertSessionHasNoErrors();

    $this->post(route('envelopes.duplicate', $this->envelope))->assertRedirect();

    $copy = Envelope::query()->where('id', '!=', $this->envelope->id)->latest('id')->firstOrFail();

    $response = $this->get(route('envelopes.document.preview', $copy));
    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    $bytes = $response->streamedContent();

    // O controller promete `application/pdf`; ou o corpo é PDF, ou a promessa é falsa.
    expect(substr($bytes, 0, 5))->toBe('%PDF-');
});
