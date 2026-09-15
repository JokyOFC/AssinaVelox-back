<?php

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Models\HubSpotConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| HubSpot — OAuth por organização, tokens cifrados (docs/fase-3/conectores.md §5.1)
|--------------------------------------------------------------------------
*/

const HUBSPOT_TOKEN_URL = 'https://api.hubapi.com/oauth/2026-03/token';
const HUBSPOT_INTROSPECT_URL = 'https://api.hubapi.com/oauth/2026-03/token/introspect';
const HUBSPOT_REVOKE_URL = 'https://api.hubapi.com/oauth/2026-03/token/revoke';

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    connectorsDns();
    connectorsConfigure();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    connectorsEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
});

/**
 * @return array<string, string>
 */
function hubspotStartConnect(object $test): array
{
    $response = $test->post(route('integrations.hubspot.connect'))->assertRedirect();

    expect((string) $response->headers->get('Location'))->toStartWith('https://app.hubspot.com/oauth/authorize?');

    return connectorsQueryFrom($response);
}

test('sem o app registrado: tela diz "aguardando app" e conectar não sai para o HubSpot', function () {
    connectorsConfigure(hubspot: false);
    Http::fake();

    $this->get(route('integrations.hubspot.show'))->assertInertia(fn (Assert $page) => $page
        ->component('integrations/hubspot')
        ->where('status', 'awaiting_app')
        ->where('missing', ['HUBSPOT_CLIENT_ID', 'HUBSPOT_CLIENT_SECRET'])
        ->where('connection', null));

    $response = $this->from(route('integrations.hubspot.show'))->post(route('integrations.hubspot.connect'));
    $response->assertRedirect(route('integrations.hubspot.show'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'aguardando app registrado pelo proprietário'));

    Http::assertNothingSent();
});

test('conectar: state de uso único, credenciais no corpo, tokens cifrados em repouso e trilha sem segredo', function () {
    Http::fake([HUBSPOT_TOKEN_URL => Http::response([
        'access_token' => 'HUBSPOT-ACCESS-NOVO',
        'refresh_token' => 'HUBSPOT-REFRESH-NOVO',
        'expires_in' => 1800,
        'hub_id' => 777001,
        'scopes' => ['oauth', 'crm.objects.deals.write'],
    ])]);

    $query = hubspotStartConnect($this);
    expect($query['client_id'])->toBe('hubspot-client-id')
        ->and($query['redirect_uri'])->toBe(route('integrations.hubspot.callback'))
        ->and($query['scope'])->toContain('crm.objects.deals.write')
        ->and(strlen($query['state']))->toBeGreaterThanOrEqual(43);

    $callback = route('integrations.hubspot.callback', ['state' => $query['state'], 'code' => 'codigo-hubspot']);
    $this->get($callback)->assertRedirect(route('integrations.hubspot.show'))->assertSessionHas('success');

    Http::assertSent(fn (Request $request): bool => $request->url() === HUBSPOT_TOKEN_URL
        && $request->data()['grant_type'] === 'authorization_code'
        && $request->data()['code'] === 'codigo-hubspot'
        && $request->data()['client_secret'] === 'HUBSPOT-CLIENT-SECRET-SENTINELA');

    $connection = HubSpotConnection::withoutOrganizationScope()->sole();
    expect($connection->organization_id)->toBe($this->organization->getKey())
        ->and($connection->portal_id)->toBe(777001)
        ->and($connection->access_token)->toBe('HUBSPOT-ACCESS-NOVO')
        ->and($connection->refresh_token)->toBe('HUBSPOT-REFRESH-NOVO')
        ->and($connection->connected_by_user_id)->toBe($this->owner->getKey());

    $raw = DB::table('hubspot_connections')->first();
    expect($raw->access_token)->not->toContain('HUBSPOT-ACCESS-NOVO')
        ->and($raw->refresh_token)->not->toContain('HUBSPOT-REFRESH-NOVO')
        ->and(Crypt::decryptString($raw->access_token))->toBe('HUBSPOT-ACCESS-NOVO')
        ->and((string) json_encode($connection->toArray()))->not->toContain('HUBSPOT-');

    $event = AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::HubSpotConnected->value)->sole();
    expect($event->envelope_id)->toBeNull()
        ->and($event->payload['portal_id'])->toBe(777001)
        ->and((string) json_encode($event->payload))->not->toContain('HUBSPOT-');

    // O mesmo retorno de novo: state consumido, nenhuma troca a mais.
    $this->get($callback)->assertRedirect(route('integrations.hubspot.show'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'expirou ou já foi usada'));
    Http::assertSentCount(1);

    $this->get(route('integrations.hubspot.show'))->assertInertia(fn (Assert $page) => $page
        ->where('status', 'connected')
        ->where('connection.portal_id', 777001)
        ->missing('connection.access_token'));
});

test('sem hub_id na resposta do token, o portal vem do introspect', function () {
    Http::fake([
        HUBSPOT_TOKEN_URL => Http::response(['access_token' => 'HUBSPOT-ACCESS-NOVO', 'refresh_token' => 'HUBSPOT-REFRESH-NOVO', 'expires_in' => 1800]),
        HUBSPOT_INTROSPECT_URL => Http::response(['active' => true, 'hubId' => 777002]),
    ]);

    $query = hubspotStartConnect($this);
    $this->get(route('integrations.hubspot.callback', ['state' => $query['state'], 'code' => 'codigo-hubspot']))->assertSessionHas('success');

    expect(HubSpotConnection::withoutOrganizationScope()->sole()->portal_id)->toBe(777002);
    Http::assertSent(fn (Request $request): bool => $request->url() === HUBSPOT_INTROSPECT_URL && $request->data()['token'] === 'HUBSPOT-ACCESS-NOVO');
});

test('portal já conectado a outra organização é recusado (um portal, uma organização)', function () {
    $other = createOrganizationWithOwner();
    connectorsHubSpotConnection($other['organization'], $other['owner'], 777001);

    Http::fake([HUBSPOT_TOKEN_URL => Http::response(['access_token' => 'HUBSPOT-ACCESS-NOVO', 'refresh_token' => 'HUBSPOT-REFRESH-NOVO', 'expires_in' => 1800, 'hub_id' => 777001])]);

    $query = hubspotStartConnect($this);
    $this->get(route('integrations.hubspot.callback', ['state' => $query['state'], 'code' => 'codigo-hubspot']))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'outra organização'));

    expect(HubSpotConnection::withoutOrganizationScope()->count())->toBe(1)
        ->and(HubSpotConnection::withoutOrganizationScope()->sole()->organization_id)->toBe($other['organization']->getKey());
});

test('desconectar revoga no HubSpot, apaga as chaves e registra na trilha', function () {
    connectorsHubSpotConnection($this->organization, $this->owner, 777001);
    Http::fake([HUBSPOT_REVOKE_URL => Http::response('', 200)]);

    $this->from(route('integrations.hubspot.show'))->delete(route('integrations.hubspot.disconnect'))
        ->assertRedirect(route('integrations.hubspot.show'))
        ->assertSessionHas('success');

    expect(HubSpotConnection::withoutOrganizationScope()->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->url() === HUBSPOT_REVOKE_URL && $request->data()['token'] === 'HUBSPOT-REFRESH-SENTINELA-777001');
    expect(AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::HubSpotDisconnected->value)->count())->toBe(1);
});

test('quem não gerencia integrações não vê, não conecta e não desconecta', function () {
    $member = attachMember($this->organization, MembershipRole::Member);
    actingAsMember($member, $this->organization);
    Http::fake();

    $this->get(route('integrations.hubspot.show'))->assertForbidden();
    $this->post(route('integrations.hubspot.connect'))->assertForbidden();
    $this->delete(route('integrations.hubspot.disconnect'))->assertForbidden();

    Http::assertNothingSent();
});
