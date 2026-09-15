<?php

use App\Enums\AuditEventType;
use App\Jobs\Documents\ProcessDocumentUpload;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Services\CloudImport\CloudProvider;
use App\Services\CloudImport\Dto\CloudFileSelection;
use App\Services\CloudImport\Http\ConnectorFailure;
use App\Services\Envelopes\EnvelopeAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| Segredos (roadmap T10; docs/fase-3/conectores.md §7): nenhum token, segredo ou código em
| log, trilha, banco em claro, sessão em claro, fila ou resposta.
|--------------------------------------------------------------------------
*/

const CONNECTOR_SENTINELS = [
    'ya29.SENTINELA-GOOGLE-ACCESS',
    'GOOGLE-REFRESH-SENTINELA',
    'GOOGLE-CLIENT-SECRET-SENTINELA',
    'HUBSPOT-CLIENT-SECRET-SENTINELA',
    'HUBSPOT-ACCESS-NOVO',
    'HUBSPOT-REFRESH-NOVO',
    'HUBSPOT-ACCESS-RENOVADO',
    'HUBSPOT-REFRESH-RENOVADO',
    'codigo-google',
    'codigo-hubspot',
];

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Bus::fake([ProcessDocumentUpload::class]);
    Storage::fake('documents');
    connectorsDns();
    connectorsConfigure();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    templatesEnable($this->organization);
    connectorsEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
    $this->logs = connectorsCaptureLogs();
});

/**
 * Todo lugar onde um segredo poderia vazar, em texto.
 */
function connectorsHaystack(ArrayObject $logs): string
{
    return implode("\n", [
        implode("\n", $logs->getArrayCopy()),
        (string) json_encode(AuditEvent::query()->withoutGlobalScopes()->get(['event_type', 'payload'])->toArray()),
        (string) json_encode(DB::table('cloud_imports')->get()->toArray()),
        (string) json_encode(DB::table('hubspot_action_executions')->get()->toArray()),
        (string) json_encode(DB::table('hubspot_connections')->get()->toArray()),
        (string) json_encode(DB::table('documents')->get()->toArray()),
        (string) json_encode(session()->all()),
    ]);
}

test('fluxo do Google (autorização, importação, recusa) não deixa token nem segredo em lugar nenhum', function () {
    $envelope = connectorsDraft($this->organization, $this->owner);
    connectorsFakeGoogle([
        '1AbCdEfGhIjKlMn' => ['name' => 'ok.pdf', 'mimeType' => 'application/pdf', 'bytes' => connectorsPdfBytes()],
        '2ZyXwVuTsRqPoNm' => ['name' => 'disfarcado.pdf', 'mimeType' => 'application/pdf', 'bytes' => "MZ\x90\x00"],
    ]);

    connectorsGoogleAuthorize($this, $envelope);
    $this->post(route('cloud_import.google.store', $envelope), ['file_ids' => ['2ZyXwVuTsRqPoNm']]);
    connectorsGoogleAuthorize($this, $envelope);
    $this->post(route('cloud_import.google.store', $envelope), ['file_ids' => ['1AbCdEfGhIjKlMn']]);

    $haystack = connectorsHaystack($this->logs);

    foreach (CONNECTOR_SENTINELS as $secret) {
        expect($haystack)->not->toContain($secret);
    }
});

test('fluxo do HubSpot (conexão, ação com assinatura ruim e boa, renovação e sincronização) idem', function () {
    Http::fake([
        'https://api.hubapi.com/oauth/2026-03/token' => Http::sequence()
            ->push(['access_token' => 'HUBSPOT-ACCESS-NOVO', 'refresh_token' => 'HUBSPOT-REFRESH-NOVO', 'expires_in' => 1800, 'hub_id' => 555001])
            ->push(['access_token' => 'HUBSPOT-ACCESS-RENOVADO', 'refresh_token' => 'HUBSPOT-REFRESH-RENOVADO', 'expires_in' => 1800]),
        'https://api.hubapi.com/crm/v3/objects/*' => Http::response(['id' => '9001'], 200),
    ]);

    $query = connectorsQueryFrom($this->post(route('integrations.hubspot.connect')));
    $this->get(route('integrations.hubspot.callback', ['state' => $query['state'], 'code' => 'codigo-hubspot']))->assertSessionHas('success');

    $template = templateHtml($this->organization, $this->owner, '<p>Contrato</p>', []);
    $fields = ['template_id' => $template->ulid, 'participant_1_name' => 'Maria Alves', 'participant_1_email' => 'maria@exemplo.test'];

    connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-ruim', $fields), secret: 'segredo-errado')->assertUnauthorized();
    $response = connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-boa', $fields))->assertOk();

    // Força a renovação e a sincronização.
    DB::table('hubspot_connections')->update(['token_expires_at' => Carbon::now()->subMinute()]);
    $envelope = Envelope::withoutOrganizationScope()->where('ulid', $response->json('outputFields.assinavelox_envelope_id'))->sole();
    EnvelopeAudit::record($envelope, AuditEventType::EnvelopeCompleted);

    $haystack = connectorsHaystack($this->logs).(string) json_encode($response->json());

    foreach (CONNECTOR_SENTINELS as $secret) {
        expect($haystack)->not->toContain($secret);
    }

    // A recusa da assinatura foi registrada — só com o código do motivo.
    expect(implode("\n", $this->logs->getArrayCopy()))->toContain('hubspot.action_signature_rejected')
        ->toContain('invalid_signature');
});

test('objetos com token não aparecem em dump, não serializam e as falhas não carregam URL', function () {
    $selection = new CloudFileSelection(CloudProvider::GoogleDrive, externalId: 'x', accessToken: 'ya29.SENTINELA-GOOGLE-ACCESS');

    expect(print_r($selection, true))->not->toContain('SENTINELA')
        ->and(fn () => serialize($selection))->toThrow(LogicException::class);

    $failure = new ConnectorFailure(ConnectorFailure::BLOCKED_URL, null, 'host_not_allowed');
    expect($failure->getMessage())->toBe('Chamada ao provedor falhou: blocked_url');
});
