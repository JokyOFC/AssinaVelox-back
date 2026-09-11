<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\SigningField;
use App\Services\Documents\EnvelopeReadiness as DocumentReadiness;
use App\Services\Envelopes\EnvelopeReadiness;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Documents/Support/helpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/Support/DomainHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.3 — preparo com vários documentos e a flag `multi_document`
|--------------------------------------------------------------------------
| Flag desligada (padrão): exatamente a Fase 1 — o segundo arquivo substitui o primeiro.
| Flag ligada (config E plano): acrescenta até o teto, reordena e remove um arquivo sem
| perder os campos dos demais; `ready` exige todos os arquivos prontos.
*/

beforeEach(function () {
    Queue::fake();

    $this->work = PdfFixtures::workspace();
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->owner = $owner;

    actingAsMember($owner, $organization);

    $this->envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();
});

afterEach(function () {
    cleanupDocumentsWorkspace($this->work ?? null);
});

it('com a flag desligada, o segundo arquivo substitui o primeiro (Fase 1)', function () {
    uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/a.pdf'), 'a.pdf')->assertSessionHasNoErrors();
    uploadDocument($this->envelope->fresh(), PdfFixtures::onePagePdf($this->work.'/b.pdf'), 'b.pdf')->assertSessionHasNoErrors();

    $documents = Document::query()->where('envelope_id', $this->envelope->id)->get();

    expect($documents)->toHaveCount(1)
        ->and($documents->first()->original_filename)->toBe('b.pdf')
        ->and($documents->first()->position)->toBe(1);
});

it('com a flag só na configuração (plano sem o item), continua substituindo', function () {
    config()->set('assinavelox.features.multi_document', true);

    uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/a.pdf'), 'a.pdf')->assertSessionHasNoErrors();
    uploadDocument($this->envelope->fresh(), PdfFixtures::onePagePdf($this->work.'/b.pdf'), 'b.pdf')->assertSessionHasNoErrors();

    expect(Document::query()->where('envelope_id', $this->envelope->id)->count())->toBe(1);
});

it('com a flag ligada, acrescenta arquivos na ordem e respeita o teto configurado', function () {
    domainEnableFlags($this->organization, maxDocuments: 2);

    uploadDocument($this->envelope, PdfFixtures::onePagePdf($this->work.'/a.pdf'), 'a.pdf')->assertSessionHasNoErrors();
    uploadDocument($this->envelope->fresh(), PdfFixtures::onePagePdf($this->work.'/b.pdf'), 'b.pdf')->assertSessionHasNoErrors();
    uploadDocument($this->envelope->fresh(), PdfFixtures::onePagePdf($this->work.'/c.pdf'), 'c.pdf')
        ->assertSessionHasErrors(['file' => 'Este documento aceita no máximo 2 arquivos.']);

    $documents = Document::query()->where('envelope_id', $this->envelope->id)->orderBy('position')->get();

    expect($documents->pluck('original_filename')->all())->toBe(['a.pdf', 'b.pdf'])
        ->and($documents->pluck('position')->all())->toBe([1, 2]);

    $uploaded = AuditEvent::query()->where('event_type', AuditEventType::DocumentUploaded->value)->orderBy('id')->get();

    expect($uploaded->pluck('payload.position')->all())->toBe([1, 2])
        ->and(AuditEvent::query()->where('event_type', AuditEventType::DocumentRemoved->value)->exists())->toBeFalse();
});

it('reordena os arquivos e remove um sem apagar os campos posicionados nos outros', function () {
    domainEnableFlags($this->organization);

    $ctx = domainEnvelope(['A', 'B', 'C'], [[
        'name' => 'Maria Alves',
        'email' => 'maria@exemplo.test',
        'fields' => [
            ['doc' => 0, 'type' => FieldType::Signature],
            ['doc' => 2, 'type' => FieldType::Text],
        ],
    ]], organization: $this->organization, owner: $this->owner, sent: false);

    [$a, $b, $c] = $ctx['documents'];
    $envelope = $ctx['envelope'];

    $this->patch(route('envelopes.update', ['envelope' => $envelope->ulid]), [
        'document_order' => [$c->ulid, $a->ulid, $b->ulid],
    ])->assertSessionHasNoErrors();

    expect(Document::query()->where('envelope_id', $envelope->id)->orderBy('position')->pluck('ulid')->all())
        ->toBe([$c->ulid, $a->ulid, $b->ulid])
        ->and($envelope->fresh()->document->ulid)->toBe($c->ulid)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::DocumentsReordered->value)->exists())->toBeTrue();

    // Uma ordem que não lista cada arquivo exatamente uma vez é recusada.
    $this->patch(route('envelopes.update', ['envelope' => $envelope->ulid]), [
        'document_order' => [$c->ulid, $a->ulid],
    ])->assertSessionHasErrors('document_order');

    $this->delete(route('envelopes.document.destroy', ['envelope' => $envelope->ulid, 'document' => $a->ulid]))
        ->assertSessionHasNoErrors();

    expect(Document::query()->whereKey($a->id)->exists())->toBeFalse()
        ->and(Document::query()->where('envelope_id', $envelope->id)->orderBy('position')->pluck('ulid')->all())->toBe([$c->ulid, $b->ulid])
        ->and(SigningField::query()->where('envelope_id', $envelope->id)->count())->toBe(1)
        ->and(SigningField::query()->where('envelope_id', $envelope->id)->value('document_version_id'))->toBe($ctx['versions'][2]->id);

    $this->get(route('envelopes.document.preview', ['envelope' => $envelope->ulid, 'document' => $b->ulid]))->assertOk();
});

it('só fica pronto quando TODOS os arquivos estão processados', function () {
    domainEnableFlags($this->organization);

    $ctx = domainEnvelope(['A', 'B'], [[
        'name' => 'Maria Alves',
        'email' => 'maria@exemplo.test',
        'fields' => [['doc' => 0, 'type' => FieldType::Signature]],
    ]], organization: $this->organization, owner: $this->owner, sent: false);

    $envelope = $ctx['envelope'];
    $readiness = app(DocumentReadiness::class);

    expect($readiness->recompute($envelope))->toBe(EnvelopeStatus::Ready);

    $ctx['documents'][1]->forceFill(['processing_status' => DocumentProcessingStatus::Converting])->save();

    expect($readiness->recompute($envelope->fresh()))->toBe(EnvelopeStatus::Preparing)
        ->and(EnvelopeReadiness::issues($envelope->fresh()))->toContain('O arquivo "B.pdf" ainda está sendo processado.');
});

it('posiciona campos por documento e recusa documento de outro envelope', function () {
    domainEnableFlags($this->organization);

    $ctx = domainEnvelope(['A', 'B'], [['name' => 'Maria Alves', 'email' => 'maria@exemplo.test']], organization: $this->organization, owner: $this->owner, sent: false);
    $foreign = domainEnvelope(['X'], [['name' => 'Ana', 'email' => 'ana@exemplo.test']], organization: $this->organization, owner: $this->owner, sent: false);
    $recipient = $ctx['recipients']['maria@exemplo.test'];

    $this->put(route('envelopes.fields.sync', ['envelope' => $ctx['envelope']->ulid]), [
        'fields' => [
            ['recipient_id' => $recipient->ulid, 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.08],
            ['recipient_id' => $recipient->ulid, 'document_id' => $foreign['documents'][0]->ulid, 'type' => 'text', 'page' => 1, 'x' => 0.1, 'y' => 0.3, 'w' => 0.3, 'h' => 0.05],
        ],
    ])->assertSessionHasErrors(['fields.1.document_id' => 'Este arquivo não pertence ao documento.']);

    $this->put(route('envelopes.fields.sync', ['envelope' => $ctx['envelope']->ulid]), [
        'fields' => [
            ['recipient_id' => $recipient->ulid, 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.08],
            ['recipient_id' => $recipient->ulid, 'document_id' => $ctx['documents'][1]->ulid, 'type' => 'text', 'page' => 2, 'x' => 0.1, 'y' => 0.3, 'w' => 0.3, 'h' => 0.05],
        ],
    ])->assertSessionHasNoErrors();

    $fields = SigningField::query()->where('envelope_id', $ctx['envelope']->id)->orderBy('id')->get();

    expect($fields->pluck('document_version_id')->all())->toBe([$ctx['versions'][0]->id, $ctx['versions'][1]->id])
        ->and($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Ready);

    $wizard = $this->get(route('envelopes.edit', ['envelope' => $ctx['envelope']->ulid, 'step' => 3]))->viewData('page')['props'];

    expect($wizard['documents'])->toHaveCount(2)
        ->and($wizard['limits']['max_documents'])->toBe(10)
        ->and(collect($wizard['fields'])->pluck('document_id')->all())->toBe([$ctx['documents'][0]->ulid, $ctx['documents'][1]->ulid]);
});
