<?php

use App\Enums\AuditEventType;
use App\Models\CloudImport;
use App\Models\HubSpotActionExecution;
use App\Models\HubSpotConnection;
use App\Services\CloudImport\CloudImportFeature;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\HubSpot\HubSpotFeature;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| Flags `cloud_import` e `hubspot` desligadas (o padrão): o recurso não existe (T8)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    connectorsDns();
    connectorsConfigure();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    actingAsMember($this->owner, $this->organization);
    $this->envelope = connectorsDraft($this->organization, $this->owner);
});

test('as flags nascem desligadas', function () {
    expect(config('assinavelox.features.cloud_import'))->toBeFalse()
        ->and(config('assinavelox.features.hubspot'))->toBeFalse()
        ->and(CloudImportFeature::enabled($this->organization))->toBeFalse()
        ->and(HubSpotFeature::enabled($this->organization))->toBeFalse();
})->skip(
    fn () => (bool) env('ASSINAVELOX_FEATURE_CLOUD_IMPORT', false) || (bool) env('ASSINAVELOX_FEATURE_HUBSPOT', false),
    'ambiente com a flag ligada',
);

test('com as flags desligadas todas as rotas novas respondem 404 e nada sai nem é gravado', function () {
    Http::fake();
    $envelope = $this->envelope;

    $this->get(route('cloud_import.show', $envelope))->assertNotFound();
    $this->get(route('cloud_import.google.start', $envelope))->assertNotFound();
    $this->get(route('cloud_import.google.callback', ['state' => str_repeat('a', 43), 'code' => 'x']))->assertNotFound();
    $this->post(route('cloud_import.google.token', $envelope))->assertNotFound();
    $this->post(route('cloud_import.google.store', $envelope), ['file_ids' => ['1AbCdEfGhIjK']])->assertNotFound();
    $this->post(route('cloud_import.dropbox.store', $envelope), [
        'files' => [['link' => 'https://dl.dropboxusercontent.com/x.pdf', 'name' => 'x.pdf']],
    ])->assertNotFound();

    $this->get(route('integrations.hubspot.show'))->assertNotFound();
    $this->post(route('integrations.hubspot.connect'))->assertNotFound();
    $this->get(route('integrations.hubspot.callback', ['state' => str_repeat('a', 43), 'code' => 'y']))->assertNotFound();
    $this->delete(route('integrations.hubspot.disconnect'))->assertNotFound();
    connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-desligada', []))->assertNotFound();

    expect(CloudImport::withoutOrganizationScope()->count())->toBe(0)
        ->and(HubSpotConnection::withoutOrganizationScope()->count())->toBe(0)
        ->and(HubSpotActionExecution::withoutOrganizationScope()->count())->toBe(0);

    Http::assertNothingSent();
});

test('interruptor global ligado sem o plano continua 404, inclusive para o portal conectado', function () {
    config()->set('assinavelox.features.cloud_import', true);
    config()->set('assinavelox.features.hubspot', true);
    connectorsHubSpotConnection($this->organization, $this->owner, 555001);
    Http::fake();

    $this->get(route('cloud_import.show', $this->envelope))->assertNotFound();
    $this->get(route('integrations.hubspot.show'))->assertNotFound();
    connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-sem-plano', []))->assertNotFound();

    expect(HubSpotActionExecution::withoutOrganizationScope()->count())->toBe(0);
    Http::assertNothingSent();
});

test('props compartilhadas trazem as chaves desligadas e o wizard continua o de antes', function () {
    $this->get(route('envelopes.edit', $this->envelope))->assertInertia(fn (Assert $page) => $page
        ->component('envelopes/wizard')
        ->where('features.cloud_import', false)
        ->where('features.hubspot', false));
});

test('o gancho da trilha não faz nada com a flag global desligada', function () {
    Http::fake();
    $connection = connectorsHubSpotConnection($this->organization, $this->owner, 555001);
    HubSpotActionExecution::withoutOrganizationScope()->create([
        'organization_id' => $this->organization->getKey(),
        'hubspot_connection_id' => $connection->getKey(),
        'portal_id' => 555001,
        'callback_id' => 'cb-gancho',
        'object_type' => 'DEAL',
        'object_id' => '9001',
        'envelope_id' => $this->envelope->getKey(),
        'status' => HubSpotActionExecution::STATUS_SENT,
    ]);

    EnvelopeAudit::record($this->envelope, AuditEventType::EnvelopeCompleted);

    expect(HubSpotActionExecution::withoutOrganizationScope()->sole()->sync_status)->toBe(HubSpotActionExecution::SYNC_NOT_APPLICABLE);
    Http::assertNothingSent();
});
