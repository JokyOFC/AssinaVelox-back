<?php

use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use App\Models\Envelope;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Documents\Support\DocumentFixtures;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/ApiHelpers.php';
require_once __DIR__.'/../../Documents/Support/helpers.php';

/*
| Upload pela API: as MESMAS regras da interface (tamanho máximo e inspeção do CONTEÚDO por
| App\Services\Documents\UploadInspector), só multipart, idempotente.
*/

beforeEach(function () {
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
    $this->token = apiIssueToken($this->organization, $this->owner);
    $this->envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->draft()->create();
    $this->url = '/api/v1/envelopes/'.$this->envelope->ulid.'/documents';
});

afterEach(fn () => cleanupDocumentsWorkspace($this->work ?? null));

test('conteúdo que não é PDF com extensão .pdf é recusado como na interface', function () {
    $fake = $this->work.'/contrato.pdf';
    file_put_contents($fake, "Isto é texto, não PDF.\n".str_repeat('x', 2000));

    $body = assertProblem(
        $this->post($this->url, ['file' => DocumentFixtures::upload($fake, 'contrato.pdf', 'application/pdf')], apiHeaders($this->token, apiIdem())),
        422,
        'upload-rejected',
    );

    expect($body['errors']['file'][0])->toBe($body['detail'])
        ->and($body)->toHaveKey('code')
        ->and(Document::withoutOrganizationScope()->where('envelope_id', $this->envelope->id)->exists())->toBeFalse();

    // A interface recusa o mesmo arquivo com a mesma mensagem.
    actingAsMember($this->owner, $this->organization);
    $this->withoutVite()
        ->post(route('envelopes.document.store', $this->envelope), ['file' => DocumentFixtures::upload($fake, 'contrato.pdf', 'application/pdf')])
        ->assertSessionHasErrors(['file' => $body['detail']]);
});

test('SVG é recusado (fora da lista de conteúdo aceito)', function () {
    $svg = PdfFixtures::svg($this->work.'/logo.svg');

    assertProblem($this->post($this->url, ['file' => DocumentFixtures::upload($svg, 'logo.svg', 'image/svg+xml')], apiHeaders($this->token, apiIdem())), 422, 'upload-rejected');
});

test('arquivo acima do limite da interface: 422 com erro no campo file', function () {
    config()->set('assinavelox.upload.max_mb', 1);

    $body = assertProblem(
        $this->post($this->url, ['file' => UploadedFile::fake()->create('grande.pdf', 1500, 'application/pdf')], apiHeaders($this->token, apiIdem())),
        422,
        'validation-failed',
    );

    expect($body['errors']['file'][0])->toContain('1 MB');
});

test('sem arquivo: 422', function () {
    assertProblem($this->postJson($this->url, [], apiHeaders($this->token, apiIdem())), 422, 'validation-failed');
});

test('documento já enviado não aceita arquivo: 409', function () {
    $sent = Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create();
    $fake = $this->work.'/qualquer.pdf';
    file_put_contents($fake, '%PDF-1.4 ...');

    assertProblem(
        $this->post('/api/v1/envelopes/'.$sent->ulid.'/documents', ['file' => DocumentFixtures::upload($fake, 'qualquer.pdf', 'application/pdf')], apiHeaders($this->token, apiIdem())),
        409,
        'invalid-status',
    );
});

test('PDF válido: 201, processado pelo mesmo pipeline e repetição idempotente', function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $pdf = PdfFixtures::onePagePdf($this->work.'/contrato.pdf');
    $headers = apiHeaders($this->token, apiIdem('upload-0001'));

    $first = $this->post($this->url, ['file' => DocumentFixtures::upload($pdf, 'contrato.pdf', 'application/pdf')], $headers)->assertCreated();

    expect($first->json('data.object'))->toBe('document')
        ->and($first->json('data.original_filename'))->toBe('contrato.pdf')
        ->and($first->json('data.processing_status'))->toBe(DocumentProcessingStatus::Ready->value)
        ->and($first->json('data.pages'))->toBe(1)
        ->and($first->json('data.sha256.original'))->toBe(hash_file('sha256', $pdf))
        ->and(json_encode($first->json()))->not->toContain('orgs/');

    $second = $this->post($this->url, ['file' => DocumentFixtures::upload($pdf, 'contrato.pdf', 'application/pdf')], $headers)->assertCreated();

    expect($second->json())->toBe($first->json())
        ->and(Document::withoutOrganizationScope()->where('envelope_id', $this->envelope->id)->count())->toBe(1);
});
