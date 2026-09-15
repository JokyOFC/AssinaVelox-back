<?php

use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/OpenApiContract.php';
require_once __DIR__.'/../../Phase2/Api/Support/ApiHelpers.php';
require_once __DIR__.'/../../Documents/Support/helpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Phase2/Templates/Support/TemplateHelpers.php';
require_once __DIR__.'/../../Phase2/RestHooks/Support/RestHookHelpers.php';

/*
|--------------------------------------------------------------------------
| G-SDK: as respostas REAIS da API v1 seguem sdks/openapi/v1.json (docs/fase-3/sdks.md §5)
|--------------------------------------------------------------------------
| O servidor falso responde pela especificação; este teste fecha o outro lado: a aplicação
| responde como a especificação diz — tipos, nulos e nenhuma propriedade que os SDKs não
| conheçam. Foi ele que achou os buracos corrigidos por anotação (§4).
*/

function sdkAttachReadyDocument(Envelope $envelope, int $pages = 2): DocumentVersion
{
    $document = Document::factory()->forEnvelope($envelope)->create(['processing_status' => DocumentProcessingStatus::Ready, 'page_count' => $pages]);
    $version = DocumentVersion::factory()->forDocument($document)->create(['page_count' => $pages, 'pages_meta' => DocumentVersionFactory::pagesMeta($pages)]);
    $document->forceFill(['current_version_id' => $version->id])->save();

    Storage::disk('documents')->put($version->storage_path, "%PDF-1.4\n% arquivo de teste\n");

    return $version;
}

beforeEach(function () {
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
    fakeEmailProvider();
});

afterEach(fn () => cleanupDocumentsWorkspace($this->work ?? null));

test('fluxo de envelope: cada resposta segue a especificação', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Imobiliária Horizonte']);
    apiEnable($organization);
    $token = apiIssueToken($organization, $owner);
    $headers = apiHeaders($token);

    $created = $this->postJson('/api/v1/envelopes', ['title' => 'Termo de vistoria', 'expires_in_days' => 10], apiHeaders($token, apiIdem()))->assertCreated();
    sdkAssertContract('v1.envelopes.store', $created);

    $id = $created->json('data.id');
    sdkAttachReadyDocument(Envelope::query()->where('ulid', $id)->firstOrFail());

    $recipients = $this->putJson("/api/v1/envelopes/{$id}/recipients", [
        'signing_order' => 'sequential',
        'recipients' => [
            ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com', 'role' => 'Locatária'],
            ['name' => 'João Souza', 'email' => 'joao@exemplo.com'],
        ],
    ], $headers)->assertOk();
    sdkAssertContract('v1.envelopes.recipients.sync', $recipients);

    $fields = $this->putJson("/api/v1/envelopes/{$id}/fields", ['fields' => [
        ['recipient_id' => $recipients->json('data.0.id'), 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06, 'required' => true],
        ['recipient_id' => $recipients->json('data.1.id'), 'type' => 'signature', 'page' => 2, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06],
    ]], $headers)->assertOk();
    sdkAssertContract('v1.envelopes.fields.sync', $fields);

    sdkAssertContract('v1.envelopes.show', $this->getJson("/api/v1/envelopes/{$id}", $headers)->assertOk());
    sdkAssertContract('v1.envelopes.index', $this->getJson('/api/v1/envelopes?per_page=1', $headers)->assertOk());
    sdkAssertContract('v1.envelopes.recipients.index', $this->getJson("/api/v1/envelopes/{$id}/recipients", $headers)->assertOk());
    sdkAssertContract('v1.envelopes.fields.index', $this->getJson("/api/v1/envelopes/{$id}/fields", $headers)->assertOk());

    $sent = $this->postJson("/api/v1/envelopes/{$id}/send", [], apiHeaders($token, apiIdem()))->assertOk();
    sdkAssertContract('v1.envelopes.send', $sent);

    sdkAssertContract('v1.envelopes.show', $this->getJson("/api/v1/envelopes/{$id}", $headers)->assertOk());
    sdkAssertContract('v1.envelopes.events.index', $this->getJson("/api/v1/envelopes/{$id}/events?per_page=2", $headers)->assertOk());
    sdkAssertContract('v1.envelopes.verification.show', $this->getJson("/api/v1/envelopes/{$id}/verification", $headers)->assertOk());
    sdkAssertContract('v1.envelopes.index', $this->getJson('/api/v1/envelopes?status=in_progress', $headers)->assertOk());

    $canceled = $this->postJson("/api/v1/envelopes/{$id}/cancel", ['reason' => 'Proposta retirada'], $headers)->assertOk();
    sdkAssertContract('v1.envelopes.cancel', $canceled);
    expect($canceled->json('meta.recipients_notified'))->toBeInt();

    $download = $this->get("/api/v1/envelopes/{$id}/files/original", $headers)->assertOk();
    expect($download->streamedContent())->toStartWith('%PDF');
});

test('modelos: listar, detalhar e gerar seguem a especificação', function () {
    $work = templatesWorkspace();

    try {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
        apiEnable($organization);
        templatesEnable($organization);
        $token = apiIssueToken($organization, $owner);

        $template = templateHtml($organization, $owner, '<p>Locatário: {{nome}}. Aluguel: {{valor}}.</p>', [
            ['key' => 'nome', 'label' => 'Nome do locatário', 'type' => 'text', 'required' => true],
            ['key' => 'valor', 'label' => 'Valor do aluguel', 'type' => 'currency', 'required' => false],
        ]);
        $role = $template->currentVersion()->with('roles')->first()->roles->first()->ulid;

        sdkAssertContract('v1.templates.index', $this->getJson('/api/v1/templates', apiHeaders($token))->assertOk());
        sdkAssertContract('v1.templates.show', $this->getJson('/api/v1/templates/'.$template->ulid, apiHeaders($token))->assertOk());

        if (PdfFixtures::available()) {
            $generated = $this->postJson('/api/v1/templates/'.$template->ulid.'/envelopes', [
                'values' => ['nome' => 'Maria Alves', 'valor' => '1500,00'],
                'participants' => [$role => ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']],
            ], apiHeaders($token, apiIdem()))->assertCreated();
            sdkAssertContract('v1.templates.envelopes.store', $generated);
        }
    } finally {
        PdfFixtures::cleanup($work);
    }
});

test('REST Hooks: catálogo, exemplo, assinar, listar e remover seguem a especificação e overrides.json', function () {
    ['organization' => $organization, 'owner' => $owner] = restHooksOrg();
    [$plain] = restHookToken($organization, $owner);
    $headers = apiHeaders($plain);

    sdkAssertContract('v1.webhook_events.index', $this->getJson('/api/v1/webhook-events', $headers)->assertOk());
    sdkAssertContract('v1.webhook_events.sample', $this->getJson('/api/v1/webhook-events/envelope.completed/sample', $headers)->assertOk());

    $created = restSubscribe($plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.completed'])->assertCreated();
    sdkAssertContract('v1.webhook_subscriptions.store', $created);
    expect($created->json('data.secret'))->toStartWith('whsec_');

    sdkAssertContract('v1.webhook_subscriptions.store', restSubscribe($plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.completed'])->assertOk());
    sdkAssertContract('v1.webhook_subscriptions.index', $this->getJson('/api/v1/webhook-subscriptions', $headers)->assertOk());

    $deleted = $this->deleteJson('/api/v1/webhook-subscriptions/'.$created->json('data.id'), [], $headers)->assertNoContent();
    sdkAssertContract('v1.webhook_subscriptions.destroy', $deleted);
});
