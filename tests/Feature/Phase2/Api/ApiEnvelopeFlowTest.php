<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Folder;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Documents\Support\DocumentFixtures;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/ApiHelpers.php';
require_once __DIR__.'/../../Documents/Support/helpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';

/*
| Fluxo completo pela API (roadmap §2.15 — aceite): criar → arquivo → participantes → campos →
| enviar → consultar → baixar → cancelar, com os MESMOS serviços da interface.
*/

beforeEach(function () {
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
    $this->provider = fakeEmailProvider();

    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Imobiliária Horizonte']);
    apiEnable($this->organization);
    $this->token = apiIssueToken($this->organization, $this->owner);
});

afterEach(fn () => cleanupDocumentsWorkspace($this->work ?? null));

/**
 * Documento já processado (sem pdftool), com o arquivo no disco privado.
 */
function apiAttachReadyDocument(Envelope $envelope, int $pages = 2): DocumentVersion
{
    $document = Document::factory()->forEnvelope($envelope)->create(['processing_status' => DocumentProcessingStatus::Ready, 'page_count' => $pages]);
    $version = DocumentVersion::factory()->forDocument($document)->create(['page_count' => $pages, 'pages_meta' => DocumentVersionFactory::pagesMeta($pages)]);
    $document->forceFill(['current_version_id' => $version->id])->save();

    Storage::disk('documents')->put($version->storage_path, "%PDF-1.4\n% arquivo de teste\n");

    return $version;
}

/**
 * @return array<string, mixed>
 */
function apiSignatureField(string $recipientId, int $page = 1): array
{
    return ['recipient_id' => $recipientId, 'type' => 'signature', 'page' => $page, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06, 'required' => true];
}

test('fluxo completo: criar → upload → participantes → campos → enviar → consultar → baixar → cancelar', function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $headers = apiHeaders($this->token);

    $created = $this->postJson('/api/v1/envelopes', ['title' => 'Contrato de locação — Apto 302', 'signing_order' => 'sequential', 'expires_in_days' => 15], apiHeaders($this->token, apiIdem()))
        ->assertCreated();
    $id = $created->json('data.id');

    expect($created->json('data.status'))->toBe('draft')
        ->and($created->json('data.expiration_days'))->toBe(15)
        ->and($created->headers->get('Location'))->toEndWith('/api/v1/envelopes/'.$id);

    $pdf = PdfFixtures::onePagePdf($this->work.'/contrato.pdf');
    $this->post('/api/v1/envelopes/'.$id.'/documents', ['file' => DocumentFixtures::upload($pdf, 'contrato.pdf', 'application/pdf')], apiHeaders($this->token, apiIdem()))
        ->assertCreated()
        ->assertJsonPath('data.processing_status', 'ready');

    $recipients = $this->putJson('/api/v1/envelopes/'.$id.'/recipients', [
        'signing_order' => 'sequential',
        'recipients' => [['name' => 'Maria Alves', 'email' => 'maria@exemplo.com', 'role' => 'Locatária']],
    ], $headers)->assertOk()->json('data');

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]['label'])->toBe('Locatária')
        ->and($recipients[0]['status'])->toBe('pending');

    $fields = $this->putJson('/api/v1/envelopes/'.$id.'/fields', ['fields' => [apiSignatureField($recipients[0]['id'])]], $headers)
        ->assertOk()->json('data');
    expect($fields)->toHaveCount(1)->and($fields[0]['recipient_id'])->toBe($recipients[0]['id']);

    $this->getJson('/api/v1/envelopes/'.$id, $headers)->assertJsonPath('data.status', 'ready');

    $sent = $this->postJson('/api/v1/envelopes/'.$id.'/send', [], apiHeaders($this->token, apiIdem()))->assertOk();
    expect($sent->json('data.status'))->toBe('in_progress')
        ->and($sent->json('meta.invitations_sent'))->toBe(1)
        ->and($sent->json('data.verification_code'))->toMatch('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/');

    $this->getJson('/api/v1/envelopes/'.$id.'/recipients', $headers)->assertJsonPath('data.0.status', 'notified');

    $types = collect($this->getJson('/api/v1/envelopes/'.$id.'/events', $headers)->assertOk()->json('data'))->pluck('type')->all();
    expect($types)->toContain('envelope.created', 'document.uploaded', 'recipients.updated', 'fields.updated', 'envelope.sent');

    $this->getJson('/api/v1/envelopes/'.$id.'/verification', $headers)->assertOk()
        ->assertJsonPath('data.verification_code', $sent->json('data.verification_code'));

    $download = $this->get('/api/v1/envelopes/'.$id.'/files/original', $headers)->assertOk();
    expect($download->streamedContent())->toBe(file_get_contents($pdf));
    assertProblem($this->getJson('/api/v1/envelopes/'.$id.'/files/signed', $headers), 404, 'not-found');

    $canceled = $this->postJson('/api/v1/envelopes/'.$id.'/cancel', ['reason' => 'Proposta retirada'], $headers)->assertOk();
    expect($canceled->json('data.status'))->toBe('canceled')
        ->and($canceled->json('data.cancel_reason'))->toBe('Proposta retirada');
});

test('fluxo sem pdftool (arquivo já processado): participantes, campos, envio, trilha e download', function () {
    $headers = apiHeaders($this->token);

    $id = $this->postJson('/api/v1/envelopes', ['title' => 'Termo de vistoria'], apiHeaders($this->token, apiIdem()))->assertCreated()->json('data.id');
    $envelope = Envelope::query()->where('ulid', $id)->firstOrFail();
    apiAttachReadyDocument($envelope);

    $recipients = $this->putJson('/api/v1/envelopes/'.$id.'/recipients', [
        'signing_order' => 'parallel',
        'recipients' => [
            ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
            ['name' => 'João Souza', 'email' => 'joao@exemplo.com'],
        ],
    ], $headers)->assertOk()->json('data');

    // Manter um participante pelo `id` e remover o outro.
    $kept = $this->putJson('/api/v1/envelopes/'.$id.'/recipients', [
        'signing_order' => 'parallel',
        'recipients' => [['id' => $recipients[0]['id'], 'name' => 'Maria Alves Pereira', 'email' => 'maria@exemplo.com']],
    ], $headers)->assertOk()->json('data');
    expect($kept)->toHaveCount(1)->and($kept[0]['id'])->toBe($recipients[0]['id'])->and($kept[0]['name'])->toBe('Maria Alves Pereira');

    // Geometria inválida: 422 com erro por campo, vindo do mesmo serviço do wizard.
    $invalid = assertProblem($this->putJson('/api/v1/envelopes/'.$id.'/fields', ['fields' => [apiSignatureField($kept[0]['id'], 9)]], $headers), 422, 'validation-failed');
    expect(array_keys($invalid['errors']))->toContain('fields.0.page');

    $this->putJson('/api/v1/envelopes/'.$id.'/fields', ['fields' => [apiSignatureField($kept[0]['id'], 2)]], $headers)->assertOk();
    $this->getJson('/api/v1/envelopes/'.$id.'/fields', $headers)->assertOk()->assertJsonPath('data.0.page', 2);

    $this->postJson('/api/v1/envelopes/'.$id.'/send', [], apiHeaders($this->token, apiIdem()))->assertOk()->assertJsonPath('data.status', 'in_progress');

    // Depois do envio a preparação está congelada: 409.
    assertProblem($this->putJson('/api/v1/envelopes/'.$id.'/recipients', ['signing_order' => 'parallel', 'recipients' => [['name' => 'Outra Pessoa', 'email' => 'outra@exemplo.com']]], $headers), 409, 'invalid-status');
    assertProblem($this->putJson('/api/v1/envelopes/'.$id.'/fields', ['fields' => []], $headers), 409, 'invalid-status');

    $this->get('/api/v1/envelopes/'.$id.'/files/original', $headers)->assertOk();
    expect(AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', AuditEventType::EnvelopeDownloaded->value)->count())->toBe(1);

    $created = AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', AuditEventType::EnvelopeCreated->value)->firstOrFail();
    expect($created->payload)->toBe(['channel' => 'api'])
        ->and($created->actor_id)->toBe($this->owner->id);
});

test('envio de documento incompleto: 409 com as pendências', function () {
    $id = $this->postJson('/api/v1/envelopes', ['title' => 'Sem nada'], apiHeaders($this->token, apiIdem()))->json('data.id');

    $body = assertProblem($this->postJson('/api/v1/envelopes/'.$id.'/send', [], apiHeaders($this->token, apiIdem())), 409, 'envelope-not-ready');

    expect($body['issues'])->toBeArray()->not->toBe([]);
});

test('listagem: filtros e paginação por cursor, sem sobreposição', function () {
    $folder = Folder::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Locações']);

    foreach (range(1, 5) as $i) {
        Envelope::factory()->forOrganization($this->organization, $this->owner)->draft()->create(['title' => "Contrato {$i}", 'folder_id' => $i <= 2 ? $folder->id : null]);
    }
    Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create(['title' => 'Enviado']);

    $seen = [];
    $url = '/api/v1/envelopes?per_page=2&status=draft';

    while ($url !== null) {
        $page = $this->getJson($url, apiHeaders($this->token))->assertOk();
        expect(count($page->json('data')))->toBeLessThanOrEqual(2)
            ->and($page->json('meta.per_page'))->toBe(2);

        array_push($seen, ...collect($page->json('data'))->pluck('id')->all());
        $url = $page->json('links.next');
    }

    expect($seen)->toHaveCount(5)
        ->and(array_unique($seen))->toHaveCount(5)
        ->and($seen)->toBe(collect($seen)->sortDesc()->values()->all());

    expect($this->getJson('/api/v1/envelopes?status=in_progress,completed', apiHeaders($this->token))->json('data.0.title'))->toBe('Enviado')
        ->and($this->getJson('/api/v1/envelopes?folder='.$folder->ulid, apiHeaders($this->token))->json('data'))->toHaveCount(2)
        ->and($this->getJson('/api/v1/envelopes?q=Contrato%203', apiHeaders($this->token))->json('data.0.title'))->toBe('Contrato 3');

    assertProblem($this->getJson('/api/v1/envelopes?status=inventado', apiHeaders($this->token)), 422, 'validation-failed');
    assertProblem($this->getJson('/api/v1/envelopes?per_page=500', apiHeaders($this->token)), 422, 'validation-failed');
});

test('formato estável: ULID, datas ISO-8601 UTC, nenhum id interno', function () {
    $envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create();

    $data = $this->getJson('/api/v1/envelopes/'.$envelope->ulid, apiHeaders($this->token))->assertOk()->json('data');

    expect($data['id'])->toHaveLength(26)
        ->and($data['object'])->toBe('envelope')
        ->and($data['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($data['sent_at'])->toMatch('/Z$/')
        ->and($data)->not->toHaveKeys(['organization_id', 'created_by_user_id', 'number', 'settings', 'finalization_key'])
        ->and($data['links']['self'])->toEndWith('/api/v1/envelopes/'.$envelope->ulid)
        ->and($data['created_by'])->toBe(['name' => $this->owner->name]);
});
