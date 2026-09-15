<?php

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientRole;
use App\Models\AnchorScan;
use App\Models\FieldSuggestion;
use App\Models\SigningField;
use App\Services\Anchors\AnchorFinder;
use App\Services\Anchors\AnchorQuery;
use App\Services\Anchors\SuggestionStatus;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Envelopes\FieldGeometry;
use App\Services\Envelopes\FieldSync;
use App\Services\Envelopes\PageBox;
use App\Services\Pdf\PdfToolClient;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/AnchorHelpers.php';

/*
| Detecção no texto do PDF (pdftool real, fixtures geradas com reportlab): marcadores e
| literais viram SUGESTÕES pendentes; o envelope só volta a ficar pronto depois da revisão.
*/

beforeEach(function () {
    anchorsRequirePdftool();
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    anchorsIsolatedDisk($this->work);

    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    anchorsEnable($this->organization);
    actingAsMember($this->owner, $this->organization);

    $this->people = [
        ['name' => 'Ana Souza', 'email' => 'ana@example.com', 'role_label' => 'Locatário'],
        ['name' => 'Bruno Lima', 'email' => 'bruno@example.com', 'role_label' => 'Fiador'],
    ];
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('marcadores viram sugestões pendentes e o envelope sai de pronto', function () {
    $pdf = anchorsPdf($this->work, [
        ['texts' => [[72, 700, '{{assinatura:locatario}}'], [330, 700, '{{rubrica:2}}'], [72, 400, 'CONFIDENCIAL <b>segredo</b>']]],
        ['texts' => [[72, 600, '{{texto:observacoes}}']], 'rotate' => 90],
    ]);
    ['envelope' => $envelope, 'document' => $document, 'recipients' => [$ana, $bruno]] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people);

    expect($envelope->status)->toBe(EnvelopeStatus::Ready);

    $response = $this->postJson(route('anchors.envelope.detect', $envelope), ['markers' => true])
        ->assertStatus(202)
        ->assertJsonPath('pending_count', 3)
        ->assertJsonPath('busy', false)
        ->assertJsonPath('documents.0.ocr_status', 'not_needed');

    $byType = collect($response->json('suggestions'))->keyBy('type');

    expect($byType['signature'])->toMatchArray(['recipient_id' => $ana->ulid, 'role_hint' => 'locatario', 'via' => 'text', 'source' => 'marker', 'page' => 1, 'requires_explicit_review' => false])
        ->and($byType['initials'])->toMatchArray(['recipient_id' => $bruno->ulid, 'role_hint' => '2'])
        ->and($byType['text'])->toMatchArray(['recipient_id' => null, 'label' => 'Observacoes', 'page' => 2]);

    // T6: nada do texto do documento sai na resposta.
    expect(json_encode($response->json()))->not->toContain('CONFIDENCIAL')->not->toContain('segredo');

    $scan = AnchorScan::query()->sole();
    expect($scan->status->value)->toBe('done')
        ->and($scan->trigger)->toBe('manual')
        ->and($scan->suggestions_count)->toBe(3)
        ->and($scan->requested_by_user_id)->toBe($this->owner->id)
        ->and($document->fresh()->getAttribute('ocr_status'))->toBe('not_needed');

    $fresh = $envelope->fresh();
    expect($fresh->status)->toBe(EnvelopeStatus::Draft)
        ->and(EnvelopeReadiness::issues($fresh))->toContain('Há 3 campos sugeridos aguardando revisão. Confirme ou descarte no passo 3.');

    // Nenhum campo real foi criado pela detecção.
    expect(SigningField::query()->where('envelope_id', $envelope->id)->count())->toBe(2);
});

test('a caixa devolvida pelo pdftool bate com a geometria do PHP nas 4 rotações e com CropBox deslocado', function (int $rotation, ?array $crop) {
    $page = ['texts' => [[100, 700, '{{assinatura:comprador}}']], 'rotate' => $rotation];

    if ($crop !== null) {
        $page['cropbox'] = $crop;
    }

    $pdf = anchorsPdf($this->work, [$page]);
    $meta = app(PdfToolClient::class)->inspect($pdf)->pagesMeta()[0];
    $detection = app(AnchorFinder::class)->find($pdf, new AnchorQuery(true, [], 50));

    expect($detection->matches)->toHaveCount(1);
    $box = $detection->matches[0]->box;

    [$llx, $lly, $urx, $ury] = FieldGeometry::toPdfRect(PageBox::fromPageMeta($meta), $box['x'], $box['y'], $box['width'], $box['height']);

    // O ponto onde o reportlab começou a escrever o marcador está dentro da caixa, que começa
    // em x = 100 no espaço do usuário do PDF, qualquer que seja a rotação exibida.
    expect($llx)->toBeLessThanOrEqual(101.5)->and($urx)->toBeGreaterThanOrEqual(101.0)
        ->and($lly)->toBeLessThanOrEqual(703.5)->and($ury)->toBeGreaterThanOrEqual(703.0)
        ->and($llx)->toEqualWithDelta(100.0, 1.0);
})->with([
    'rotação 0' => [0, null],
    'rotação 90' => [90, null],
    'rotação 180' => [180, null],
    'rotação 270' => [270, null],
    'rotação 90 com CropBox' => [90, [40, 50, 560, 760]],
    'rotação 270 com CropBox' => [270, [40, 50, 560, 760]],
]);

test('texto literal com participante e posição definidos pelo remetente', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 300, 'Assinatura do fiador:'], [72, 200, 'ASSINATURA   DO FIADOR']]]]);
    ['envelope' => $envelope, 'recipients' => [, $bruno]] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people);

    $response = $this->postJson(route('anchors.envelope.detect', $envelope), [
        'markers' => false,
        'literals' => [['text' => 'assinatura do Fiador', 'field_type' => 'signature', 'recipient_id' => $bruno->ulid, 'placement' => 'below']],
    ])->assertStatus(202);

    $suggestions = $response->json('suggestions');
    expect($suggestions)->toHaveCount(2)
        ->and(collect($suggestions)->pluck('source')->unique()->all())->toBe(['literal'])
        ->and(collect($suggestions)->pluck('recipient_id')->unique()->all())->toBe([$bruno->ulid]);

    // "Abaixo do texto": o campo começa abaixo da linha (y do texto em 300 pt de 800 → 0.625).
    expect($suggestions[0]['y'])->toBeGreaterThan(1 - 300 / 800 - 0.001);
});

test('confirmar devolve o campo, destrava o envelope e o campo passa pelo FieldSync', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 500, '{{data:locatario}}']]]]);
    ['envelope' => $envelope, 'document' => $document, 'recipients' => [$ana]] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people);

    $id = $this->postJson(route('anchors.envelope.detect', $envelope))->assertStatus(202)->json('suggestions.0.id');
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);

    $field = $this->postJson(route('anchors.suggestions.accept', [$envelope, $id]))
        ->assertOk()
        ->assertJsonPath('state.pending_count', 0)
        ->json('field');

    expect($field)->toMatchArray(['type' => 'date', 'recipient_id' => $ana->ulid, 'page' => 1, 'document_id' => $document->ulid, 'suggestion_id' => $id, 'via' => 'text']);

    $suggestion = FieldSuggestion::query()->where('ulid', $id)->sole();
    expect($suggestion->status)->toBe(SuggestionStatus::Accepted)
        ->and($suggestion->resolved_by_user_id)->toBe($this->owner->id)
        ->and($suggestion->resolved_at)->not->toBeNull()
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);

    // O editor salva o campo confirmado pelo caminho normal: FieldSync aceita a geometria.
    $existing = SigningField::query()->where('envelope_id', $envelope->id)->with('recipient')->get()
        ->map(fn (SigningField $f): array => ['id' => $f->ulid, 'recipient_id' => $f->recipient->ulid, 'type' => $f->type->value, 'page' => $f->page, 'x' => (float) $f->x, 'y' => (float) $f->y, 'w' => (float) $f->width, 'h' => (float) $f->height])
        ->all();

    app(FieldSync::class)->handle($envelope->fresh(), ['fields' => [...$existing, $field]]);

    expect(SigningField::query()->where('envelope_id', $envelope->id)->where('type', 'date')->count())->toBe(1)
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);

    // Revisar de novo a mesma sugestão: recusado.
    $this->postJson(route('anchors.suggestions.accept', [$envelope, $id]))->assertStatus(422)->assertJsonValidationErrors('suggestion');
});

test('sugestão sem participante exige a escolha; papéis incompatíveis são recusados', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 500, '{{texto:observacoes}}'], [72, 300, '{{assinatura:gerente}}']]]]);
    $people = [...$this->people, ['name' => 'Carla Dias', 'email' => 'carla@example.com', 'role_label' => 'Gerente', 'role' => RecipientRole::Approver]];
    ['envelope' => $envelope, 'recipients' => [, $bruno, $carla]] = anchorsEnvelope($this->organization, $this->owner, $pdf, $people);

    $suggestions = collect($this->postJson(route('anchors.envelope.detect', $envelope))->assertStatus(202)->json('suggestions'))->keyBy('type');

    // O marcador aponta para a aprovadora, que não assina: fica sem participante.
    expect($suggestions['signature']['recipient_id'])->toBeNull();

    $this->postJson(route('anchors.suggestions.accept', [$envelope, $suggestions['text']['id']]))
        ->assertStatus(422)->assertJsonValidationErrors('recipient_id');
    $this->postJson(route('anchors.suggestions.accept', [$envelope, $suggestions['signature']['id']]), ['recipient_id' => $carla->ulid])
        ->assertStatus(422)->assertJsonValidationErrors('recipient_id');
    $this->postJson(route('anchors.suggestions.accept', [$envelope, $suggestions['text']['id']]), ['recipient_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])
        ->assertStatus(422)->assertJsonValidationErrors('recipient_id');

    $this->postJson(route('anchors.suggestions.accept', [$envelope, $suggestions['text']['id']]), ['recipient_id' => $bruno->ulid])
        ->assertOk()->assertJsonPath('field.recipient_id', $bruno->ulid)->assertJsonPath('field.label', 'Observacoes');
});

test('descartar resolve a sugestão sem criar campo', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 500, '{{rubrica:locatario}}']]]]);
    ['envelope' => $envelope] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people);

    $id = $this->postJson(route('anchors.envelope.detect', $envelope))->json('suggestions.0.id');

    $this->postJson(route('anchors.suggestions.discard', [$envelope, $id]))->assertOk()->assertJsonPath('state.pending_count', 0);

    expect(FieldSuggestion::query()->where('ulid', $id)->value('status'))->toBe(SuggestionStatus::Discarded)
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);
});

test('confirmar todas aceita só as do texto com participante', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 600, '{{data:locatario}}'], [72, 500, '{{data:fiador}}'], [72, 400, '{{texto:obs}}']]]]);
    ['envelope' => $envelope] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people);

    $this->postJson(route('anchors.envelope.detect', $envelope))->assertJsonPath('pending_count', 3);

    $response = $this->postJson(route('anchors.suggestions.accept_all', $envelope))->assertOk();

    expect($response->json('fields'))->toHaveCount(2)
        ->and($response->json('skipped'))->toBe(1)
        ->and($response->json('state.pending_count'))->toBe(1)
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);
});

test('uma nova detecção substitui as sugestões pendentes anteriores', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 600, '{{data:locatario}}']]]]);
    ['envelope' => $envelope] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people);

    $first = $this->postJson(route('anchors.envelope.detect', $envelope))->json('suggestions.0.id');
    $this->postJson(route('anchors.envelope.detect', $envelope))->assertJsonPath('pending_count', 1);

    expect(FieldSuggestion::query()->where('ulid', $first)->value('status'))->toBe(SuggestionStatus::Superseded)
        ->and(AnchorScan::query()->count())->toBe(2);
});

test('sugestão que repete um campo já posicionado não é criada', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 600, '{{data:locatario}}']]]]);
    ['envelope' => $envelope, 'version' => $version, 'recipients' => [$ana]] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people);

    $id = $this->postJson(route('anchors.envelope.detect', $envelope))->json('suggestions.0.id');
    $s = FieldSuggestion::query()->where('ulid', $id)->sole();
    SigningField::factory()->create([
        'envelope_id' => $envelope->id, 'document_version_id' => $version->id, 'recipient_id' => $ana->id,
        'organization_id' => $this->organization->id, 'type' => 'date', 'page' => 1,
        'x' => $s->x, 'y' => $s->y, 'width' => $s->width, 'height' => $s->height,
    ]);

    $this->postJson(route('anchors.envelope.detect', $envelope))->assertJsonPath('pending_count', 0);
});

test('PDF criptografado: busca falha com mensagem clara e não trava o preparo', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 600, '{{data:locatario}}']]]], encryptOwner: 'dono');
    ['envelope' => $envelope] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people, encrypted: true);

    $response = $this->postJson(route('anchors.envelope.detect', $envelope))->assertStatus(202)->assertJsonPath('pending_count', 0);

    $message = $response->json('documents.0.last_scan.failure_message');
    expect($response->json('documents.0.last_scan.status'))->toBe('failed')
        ->and($message)->toContain('protegido por senha')
        ->and($message)->not->toContain($this->work)
        ->and($message)->not->toContain('storage')
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);
});

test('página sem texto com o OCR fora do plano: conta as páginas e não mexe no ocr_status', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 600, '{{data:locatario}}']]], ['box' => true]]);
    ['envelope' => $envelope, 'document' => $document] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people);

    $this->postJson(route('anchors.envelope.detect', $envelope))
        ->assertStatus(202)
        ->assertJsonPath('documents.0.last_scan.pages_without_text', [2])
        ->assertJsonPath('documents.0.last_ocr_scan', null)
        ->assertJsonPath('ocr.enabled', false);

    expect($document->fresh()->getAttribute('ocr_status'))->toBeNull();
});

test('validação do pedido de detecção', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 600, 'nada aqui']]]]);
    ['envelope' => $envelope] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people);
    $other = anchorsEnvelope($this->organization, $this->owner, $pdf, [['name' => 'Zé', 'email' => 'ze@example.com']])['recipients'][0];

    $url = route('anchors.envelope.detect', $envelope);

    $this->postJson($url, ['markers' => false])->assertStatus(422)->assertJsonValidationErrors('markers');
    $this->postJson($url, ['literals' => [['text' => '.*', 'field_type' => 'signature']]])->assertStatus(422)->assertJsonValidationErrors('literals.0.text');
    $this->postJson($url, ['literals' => [['text' => 'Assinatura', 'field_type' => 'stamp']]])->assertStatus(422)->assertJsonValidationErrors('literals.0.field_type');
    $this->postJson($url, ['literals' => [['text' => 'Assinatura', 'field_type' => 'signature', 'recipient_id' => $other->ulid]]])->assertStatus(422)->assertJsonValidationErrors('literals.0.recipient_id');
    $this->postJson($url, ['literals' => array_fill(0, 11, ['text' => 'Assinatura', 'field_type' => 'signature'])])->assertStatus(422)->assertJsonValidationErrors('literals');

    expect(AnchorScan::query()->count())->toBe(0);
});

test('documento enviado não aceita detecção nem revisão', function () {
    $pdf = anchorsPdf($this->work, [['texts' => [[72, 600, '{{data:locatario}}']]]]);
    ['envelope' => $envelope] = anchorsEnvelope($this->organization, $this->owner, $pdf, $this->people);
    $id = $this->postJson(route('anchors.envelope.detect', $envelope))->json('suggestions.0.id');

    $envelope->forceFill(['status' => EnvelopeStatus::InProgress])->save();

    $this->postJson(route('anchors.envelope.detect', $envelope))->assertStatus(409);
    $this->postJson(route('anchors.suggestions.accept', [$envelope, $id]))->assertStatus(422)->assertJsonValidationErrors('suggestion');
});
