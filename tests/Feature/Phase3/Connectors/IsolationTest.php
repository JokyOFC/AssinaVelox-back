<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Jobs\Documents\ProcessDocumentUpload;
use App\Models\CloudImport;
use App\Models\Envelope;
use App\Models\HubSpotActionExecution;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| Isolamento entre organizações (docs/autorizacao-e-isolamento.md; conectores.md §9)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Bus::fake([ProcessDocumentUpload::class]);
    Storage::fake('documents');
    connectorsDns();
    connectorsConfigure();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    ['organization' => $this->otherOrganization, 'owner' => $this->otherOwner] = createOrganizationWithOwner();
    connectorsEnable($this->organization);
    connectorsEnable($this->otherOrganization);
    $this->envelope = connectorsDraft($this->organization, $this->owner);
    $this->otherEnvelope = connectorsDraft($this->otherOrganization, $this->otherOwner);
});

test('envelope de outra organização: página e importações respondem 404 e nada sai', function () {
    actingAsMember($this->owner, $this->organization);
    Http::fake();

    $this->get(route('cloud_import.show', $this->otherEnvelope))->assertNotFound();
    $this->get(route('cloud_import.google.start', $this->otherEnvelope))->assertNotFound();
    $this->post(route('cloud_import.google.token', $this->otherEnvelope))->assertNotFound();
    $this->post(route('cloud_import.dropbox.store', $this->otherEnvelope), [
        'files' => [['link' => 'https://dl.dropboxusercontent.com/1/view/a/b.pdf', 'name' => 'b.pdf']],
    ])->assertNotFound();

    expect(CloudImport::withoutOrganizationScope()->count())->toBe(0);
    Http::assertNothingSent();
});

test('o token do Google autorizado para um envelope não vale para outro envelope', function () {
    actingAsMember($this->owner, $this->organization);
    $second = connectorsDraft($this->organization, $this->owner);
    connectorsFakeGoogle(['1AbCdEfGhIjKlMn' => ['name' => 'a.pdf', 'mimeType' => 'application/pdf', 'bytes' => connectorsPdfBytes()]]);
    connectorsGoogleAuthorize($this, $this->envelope);

    $this->post(route('cloud_import.google.token', $second))->assertStatus(409);
    $this->post(route('cloud_import.google.store', $second), ['file_ids' => ['1AbCdEfGhIjKlMn']])
        ->assertRedirect(route('cloud_import.show', $second))
        ->assertSessionHas('error');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/drive/v3/files/'));
});

test('retorno do Google iniciado numa organização não vale com outra organização corrente', function () {
    attachMember($this->otherOrganization, MembershipRole::Admin, MembershipStatus::Active, $this->owner);
    actingAsMember($this->owner, $this->organization);
    Http::fake();

    $query = connectorsQueryFrom($this->get(route('cloud_import.google.start', $this->envelope)));

    actingAsMember($this->owner, $this->otherOrganization);
    $this->get(route('cloud_import.google.callback', ['state' => $query['state'], 'code' => 'c']))
        ->assertRedirect(route('envelopes.index'))
        ->assertSessionHas('error');

    Http::assertNothingSent();
});

test('ação do HubSpot: o portal decide a organização; modelo de outra organização não é aceito', function () {
    templatesEnable($this->organization);
    $foreignTemplate = templateHtml($this->otherOrganization, $this->otherOwner, '<p>Modelo de outra organização</p>', []);
    connectorsHubSpotConnection($this->organization, $this->owner, 555001);
    $before = Envelope::withoutOrganizationScope()->count();

    connectorsHubSpotPost($this, connectorsActionPayload(555001, 'cb-alheio', [
        'template_id' => $foreignTemplate->ulid,
        'participant_1_name' => 'Maria Alves',
        'participant_1_email' => 'maria@exemplo.test',
    ]))->assertOk()->assertJsonPath('outputFields.assinavelox_status', 'failed');

    expect(HubSpotActionExecution::withoutOrganizationScope()->sole()->error_code)->toBe('template_not_found')
        ->and(HubSpotActionExecution::withoutOrganizationScope()->sole()->organization_id)->toBe($this->organization->getKey())
        ->and(Envelope::withoutOrganizationScope()->count())->toBe($before);
});

test('conexão e execuções de uma organização não aparecem na tela da outra', function () {
    $connection = connectorsHubSpotConnection($this->organization, $this->owner, 555001);
    HubSpotActionExecution::withoutOrganizationScope()->create([
        'organization_id' => $this->organization->getKey(),
        'hubspot_connection_id' => $connection->getKey(),
        'portal_id' => 555001,
        'callback_id' => 'cb-a',
        'envelope_id' => $this->envelope->getKey(),
        'status' => HubSpotActionExecution::STATUS_SENT,
    ]);

    actingAsMember($this->otherOwner, $this->otherOrganization);

    $this->get(route('integrations.hubspot.show'))->assertInertia(fn (Assert $page) => $page
        ->component('integrations/hubspot')
        ->where('status', 'disconnected')
        ->where('connection', null)
        ->where('executions', []));

    Http::fake();
    $this->delete(route('integrations.hubspot.disconnect'))->assertSessionHas('info');
    expect($connection->fresh())->not->toBeNull();
    Http::assertNothingSent();
});
