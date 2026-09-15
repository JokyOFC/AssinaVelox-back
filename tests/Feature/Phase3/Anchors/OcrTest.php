<?php

use App\Enums\EnvelopeStatus;
use App\Integrations\Ocr\FakeOcrEngine;
use App\Integrations\Ocr\NullOcrEngine;
use App\Integrations\Ocr\OcrAvailability;
use App\Integrations\Ocr\OcrEngine;
use App\Integrations\Ocr\OcrEngines;
use App\Integrations\Ocr\TesseractOcrEngine;
use App\Models\AnchorScan;
use App\Models\FieldSuggestion;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/AnchorHelpers.php';

/*
| OCR (classe B): o Tesseract NÃO está instalado neste ambiente. A verificação é real — sem o
| binário a tela diz "OCR indisponível neste servidor" —, o motor de teste é identificado
| (`fake`) e a falha do OCR nunca trava o preparo manual.
*/

beforeEach(function () {
    anchorsRequirePdftool();
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('assinavelox.ocr.driver', 'tesseract');
    config()->set('assinavelox.ocr.tesseract_path', null);
    anchorsIsolatedDisk($this->work);

    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    anchorsEnable($this->organization, ocr: true);
    actingAsMember($this->owner, $this->organization);

    $this->people = [
        ['name' => 'Ana Souza', 'email' => 'ana@example.com', 'role_label' => 'Locatário'],
        ['name' => 'Bruno Lima', 'email' => 'bruno@example.com', 'role_label' => 'Fiador'],
    ];
    // Página 1 com texto; página 2 "escaneada" (só desenho, nenhum caractere).
    $this->pages = [['texts' => [[72, 600, '{{data:locatario}}']]], ['box' => true]];
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('sem Tesseract a tela diz "OCR indisponível neste servidor" e o fluxo manual segue', function () {
    ['envelope' => $envelope, 'document' => $document] = anchorsEnvelope($this->organization, $this->owner, anchorsPdf($this->work, $this->pages), $this->people);

    $response = $this->postJson(route('anchors.envelope.detect', $envelope))
        ->assertStatus(202)
        ->assertJsonPath('ocr.enabled', true)
        ->assertJsonPath('ocr.available', false)
        ->assertJsonPath('ocr.message', 'OCR indisponível neste servidor')
        ->assertJsonPath('documents.0.ocr_status', 'unavailable')
        ->assertJsonPath('documents.0.ocr_status_label', 'OCR indisponível neste servidor')
        ->assertJsonPath('documents.0.last_ocr_scan', null);

    expect($document->fresh()->getAttribute('ocr_status'))->toBe('unavailable')
        ->and(AnchorScan::query()->where('trigger', 'ocr')->count())->toBe(0);

    // A sugestão do texto é revisada e o envelope fica pronto: o OCR ausente não trava nada.
    $this->postJson(route('anchors.suggestions.discard', [$envelope, $response->json('suggestions.0.id')]))->assertOk();
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);
});

test('a verificação de disponibilidade do Tesseract é real', function () {
    $engine = app(TesseractOcrEngine::class);

    config()->set('assinavelox.ocr.tesseract_path', null);
    expect($engine->availability())->toEqual(OcrAvailability::unavailable(OcrAvailability::NOT_CONFIGURED));

    config()->set('assinavelox.ocr.tesseract_path', $this->work.DIRECTORY_SEPARATOR.'tesseract-que-nao-existe.exe');
    expect($engine->availability())->toEqual(OcrAvailability::unavailable(OcrAvailability::BINARY_MISSING));

    // Um executável que não é o Tesseract: `--list-langs` falha ou não lista `por`.
    config()->set('assinavelox.ocr.tesseract_path', PHP_BINARY);
    $availability = $engine->availability();
    expect($availability->available)->toBeFalse()
        ->and($availability->reason)->toBeIn([OcrAvailability::PROBE_FAILED, OcrAvailability::LANGUAGE_MISSING])
        ->and($availability->message())->toBe('OCR indisponível neste servidor');
});

test('OCR simulado: sugestões identificadas, revisão explícita e fora do "confirmar todas"', function () {
    $fake = new FakeOcrEngine([2 => [[
        'kind' => 'marker', 'field_type' => 'signature', 'key' => 'fiador',
        'box' => ['x' => 0.2, 'y' => 0.3, 'width' => 0.3, 'height' => 0.02],
        'line_box' => ['x' => 0.2, 'y' => 0.3, 'width' => 0.3, 'height' => 0.02],
    ]]]);
    app()->instance(OcrEngine::class, $fake);

    ['envelope' => $envelope, 'document' => $document, 'recipients' => [, $bruno]] = anchorsEnvelope($this->organization, $this->owner, anchorsPdf($this->work, $this->pages), $this->people);

    $response = $this->postJson(route('anchors.envelope.detect', $envelope))
        ->assertStatus(202)
        ->assertJsonPath('pending_count', 2)
        ->assertJsonPath('ocr.simulated', true)
        ->assertJsonPath('ocr.message', 'OCR simulado (ambiente de teste)')
        ->assertJsonPath('documents.0.ocr_status', 'done')
        ->assertJsonPath('documents.0.last_ocr_scan.simulated', true)
        ->assertJsonPath('documents.0.last_ocr_scan.status', 'done');

    expect($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['pages'])->toBe([2]);

    $ocr = collect($response->json('suggestions'))->firstWhere('via', 'ocr');
    expect($ocr)->toMatchArray(['page' => 2, 'recipient_id' => $bruno->ulid, 'requires_explicit_review' => true, 'type' => 'signature'])
        ->and(AnchorScan::query()->where('trigger', 'ocr')->sole()->ocr_engine)->toBe('fake');

    // "Confirmar todas" leva só a do texto; a do OCR continua pendente e o envelope, travado.
    $this->postJson(route('anchors.suggestions.accept_all', $envelope))
        ->assertOk()
        ->assertJsonCount(1, 'fields')
        ->assertJsonPath('state.pending_count', 1);
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);

    // Revisão explícita, uma a uma.
    $this->postJson(route('anchors.suggestions.accept', [$envelope, $ocr['id']]))->assertOk()->assertJsonPath('field.via', 'ocr');
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready)
        ->and($document->fresh()->getAttribute('ocr_status'))->toBe('done');
});

test('falha do OCR marca o documento e não trava o preparo', function () {
    app()->instance(OcrEngine::class, new FakeOcrEngine([], true, 'timeout'));

    ['envelope' => $envelope, 'document' => $document] = anchorsEnvelope($this->organization, $this->owner, anchorsPdf($this->work, $this->pages), $this->people);

    $response = $this->postJson(route('anchors.envelope.detect', $envelope))
        ->assertStatus(202)
        ->assertJsonPath('documents.0.ocr_status', 'failed')
        ->assertJsonPath('documents.0.last_ocr_scan.status', 'failed')
        ->assertJsonPath('busy', false);

    expect($response->json('documents.0.last_ocr_scan.failure_message'))->toContain('Posicione os campos manualmente');

    $this->postJson(route('anchors.suggestions.discard', [$envelope, $response->json('suggestions.0.id')]))->assertOk();
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready)
        ->and($document->fresh()->getAttribute('ocr_status'))->toBe('failed')
        ->and(FieldSuggestion::query()->where('via', 'ocr')->count())->toBe(0);
});

test('o motor simulado nunca é entregue fora de local/testing', function () {
    config()->set('assinavelox.ocr.driver', 'fake');
    expect(OcrEngines::make())->toBeInstanceOf(FakeOcrEngine::class);

    $this->app['env'] = 'production';

    try {
        expect(OcrEngines::make())->toBeInstanceOf(NullOcrEngine::class);
    } finally {
        $this->app['env'] = 'testing';
    }

    config()->set('assinavelox.ocr.driver', 'disabled');
    expect(OcrEngines::make())->toBeInstanceOf(NullOcrEngine::class)
        ->and(OcrEngines::make()->availability()->available)->toBeFalse();
});

test('busca de OCR parada além do prazo deixa de bloquear', function () {
    ['envelope' => $envelope, 'document' => $document, 'version' => $version] = anchorsEnvelope($this->organization, $this->owner, anchorsPdf($this->work, $this->pages), $this->people);

    $scan = new AnchorScan;
    $scan->forceFill([
        'organization_id' => $this->organization->id, 'envelope_id' => $envelope->id, 'document_id' => $document->id,
        'document_version_id' => $version->id, 'trigger' => 'ocr', 'status' => 'running', 'query' => ['markers' => true],
    ])->save();

    expect(anchorsReady($envelope))->toBeFalse();

    AnchorScan::query()->whereKey($scan->id)->update(['created_at' => now()->subMinutes(60)]);

    expect(anchorsReady($envelope))->toBeTrue();
});
