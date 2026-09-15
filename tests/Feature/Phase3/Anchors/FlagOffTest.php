<?php

use App\Enums\DocumentProcessingStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Models\AnchorScan;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\FieldSuggestion;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Anchors\AnchorFeatures;
use App\Services\Anchors\SuggestionGate;
use App\Services\Envelopes\EnvelopeReadiness;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/AnchorHelpers.php';

/*
| Flags `field_anchors` e `ocr` desligadas (o padrão, roadmap T8): as rotas não existem para a
| organização, o preparo não faz NENHUMA consulta nova e uma sugestão que por acaso exista não
| trava o envelope.
*/

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    actingAsMember($this->owner, $this->organization);
});

/**
 * Rascunho pronto (documento `ready`, um signatário com campo de assinatura) sem PDF real.
 *
 * @return array{envelope: Envelope, document: Document, version: DocumentVersion, recipient: Recipient}
 */
function anchorsFlagOffEnvelope(object $test): array
{
    $envelope = Envelope::factory()->forOrganization($test->organization, $test->owner)->draft()->create();
    $document = Document::factory()->forEnvelope($envelope)->create(['processing_status' => DocumentProcessingStatus::Ready, 'page_count' => 1]);
    $version = DocumentVersion::factory()->forDocument($document)->create(['page_count' => 1, 'pages_meta' => DocumentVersionFactory::pagesMeta(1)]);
    $document->forceFill(['current_version_id' => $version->id])->save();
    $recipient = Recipient::factory()->forEnvelope($envelope, 1)->create();
    SigningField::factory()->create([
        'envelope_id' => $envelope->id, 'document_version_id' => $version->id, 'recipient_id' => $recipient->id,
        'organization_id' => $test->organization->id, 'type' => FieldType::Signature, 'page' => 1,
        'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.06, 'required' => true,
    ]);
    EnvelopeReadiness::refresh($envelope);

    return ['envelope' => $envelope->fresh(), 'document' => $document, 'version' => $version, 'recipient' => $recipient];
}

test('flags nascem desligadas e as rotas respondem 404', function () {
    ['envelope' => $envelope] = anchorsFlagOffEnvelope($this);

    expect(config('assinavelox.features.field_anchors'))->toBeFalse()
        ->and(config('assinavelox.features.ocr'))->toBeFalse()
        ->and(AnchorFeatures::forOrganization($this->organization))->toBe(['field_anchors' => false, 'ocr' => false]);

    $this->getJson(route('anchors.envelope.index', $envelope))->assertNotFound();
    $this->postJson(route('anchors.envelope.detect', $envelope), ['markers' => true])->assertNotFound();
    $this->postJson(route('anchors.suggestions.accept_all', $envelope))->assertNotFound();
    $this->postJson(route('anchors.suggestions.accept', [$envelope, '01HZZZZZZZZZZZZZZZZZZZZZZZ']))->assertNotFound();
    $this->postJson(route('anchors.suggestions.discard', [$envelope, '01HZZZZZZZZZZZZZZZZZZZZZZZ']))->assertNotFound();
    $this->getJson(route('anchors.template.index', '01HZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
    $this->putJson(route('anchors.template.update', '01HZZZZZZZZZZZZZZZZZZZZZZZ'), ['rules' => []])->assertNotFound();

    expect(AnchorScan::query()->count())->toBe(0);
});

test('global ligada mas plano sem o recurso continua 404', function () {
    ['envelope' => $envelope] = anchorsFlagOffEnvelope($this);
    anchorsEnable($this->organization, ocr: true, plan: false);

    $this->getJson(route('anchors.envelope.index', $envelope))->assertNotFound();
    expect(AnchorFeatures::fieldAnchors($this->organization))->toBeFalse()
        ->and(AnchorFeatures::ocr($this->organization))->toBeFalse();
});

test('ocr sem field_anchors nunca liga', function () {
    config()->set('assinavelox.features.ocr', true);
    $plan = $this->organization->currentSubscription()->with('plan')->first()->plan;
    $plan->forceFill(['features' => [...(array) $plan->features, 'ocr' => true]])->save();

    expect(AnchorFeatures::ocr($this->organization))->toBeFalse();
});

test('com a flag global desligada o preparo não consulta as tabelas novas', function () {
    ['envelope' => $envelope] = anchorsFlagOffEnvelope($this);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(SuggestionGate::blocks($envelope))->toBeFalse()
        ->and(SuggestionGate::issues($envelope))->toBe([]);
    EnvelopeReadiness::refresh($envelope);
    EnvelopeReadiness::issues($envelope);

    $touched = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'field_suggestions') || str_contains($sql, 'anchor_scans'));

    DB::disableQueryLog();

    expect($touched)->toBeEmpty()
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);
});

test('uma sugestão pendente não trava o envelope com a flag desligada', function () {
    ['envelope' => $envelope, 'document' => $document, 'version' => $version] = anchorsFlagOffEnvelope($this);

    $scan = new AnchorScan;
    $scan->forceFill([
        'organization_id' => $this->organization->id, 'envelope_id' => $envelope->id, 'document_id' => $document->id,
        'document_version_id' => $version->id, 'trigger' => 'manual', 'status' => 'done', 'query' => ['markers' => true],
    ])->save();
    $suggestion = new FieldSuggestion;
    $suggestion->forceFill([
        'organization_id' => $this->organization->id, 'envelope_id' => $envelope->id, 'document_id' => $document->id,
        'document_version_id' => $version->id, 'anchor_scan_id' => $scan->id, 'source' => 'marker', 'via' => 'text',
        'type' => 'signature', 'page' => 1, 'x' => 0.5, 'y' => 0.5, 'width' => 0.2, 'height' => 0.05, 'status' => 'pending',
    ])->save();

    expect(anchorsReady($envelope))->toBeTrue()
        ->and(EnvelopeReadiness::issues($envelope->fresh()))->toBe([]);
});

test('documents.ocr_status nasce nulo', function () {
    ['document' => $document] = anchorsFlagOffEnvelope($this);

    expect($document->fresh()->getAttribute('ocr_status'))->toBeNull();
});
