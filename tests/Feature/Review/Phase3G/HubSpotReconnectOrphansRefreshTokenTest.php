<?php

use App\Models\HubSpotConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Phase3/Connectors/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial onda G (HubSpot) — reconectar não revoga o refresh token anterior
|--------------------------------------------------------------------------
| docs/fase-3/conectores.md §5 e o model dizem que o refresh token é o ÚNICO token de terceiro
| persistente e que desconectar "revoga no HubSpot". Mas o botão "Conectar" continua disponível
| com uma conexão ativa, e HubSpotConnections::connect() faz `firstOrNew(organization_id)` e
| SOBRESCREVE access_token/refresh_token/portal_id sem chamar o revoke do par anterior.
|
| O refresh token do HubSpot não vence sozinho (vale até ser revogado ou o app ser
| desinstalado). Depois de uma reconexão — outro portal ou o mesmo —, o par antigo continua
| válido no HubSpot, ninguém mais consegue revogá-lo pela tela (a linha já aponta para o par
| novo) e ele sobrevive em backups do banco (cifrado com a APP_KEY). É exatamente o "token
| persistido além do necessário" que a onda proíbe. Trocando de portal, as execuções antigas
| também ficam órfãs (a sincronização procura a conexão pelo portal antigo e marca
| `not_connected`).
|
| Correção sugerida: em connect(), se já houver conexão da organização com refresh token
| diferente do novo, revogar o anterior (melhor esforço, como em disconnect()).
*/

if (! defined('REVIEW_3G_HUBSPOT_TOKEN_URL')) {
    define('REVIEW_3G_HUBSPOT_TOKEN_URL', 'https://api.hubapi.com/oauth/2026-03/token');
    define('REVIEW_3G_HUBSPOT_REVOKE_URL', 'https://api.hubapi.com/oauth/2026-03/token/revoke');
}

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    connectorsDns();
    connectorsConfigure();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    connectorsEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
});

it('reconectar com uma conexão ativa revoga o refresh token anterior no HubSpot', function (int $newPortal) {
    connectorsHubSpotConnection($this->organization, $this->owner, 777001);

    Http::fake([
        REVIEW_3G_HUBSPOT_TOKEN_URL => Http::response([
            'access_token' => 'HUBSPOT-ACCESS-NOVO',
            'refresh_token' => 'HUBSPOT-REFRESH-NOVO',
            'expires_in' => 1800,
            'hub_id' => $newPortal,
        ]),
        REVIEW_3G_HUBSPOT_REVOKE_URL => Http::response('', 200),
    ]);

    $start = $this->post(route('integrations.hubspot.connect'))->assertRedirect();
    $query = connectorsQueryFrom($start);

    $this->get(route('integrations.hubspot.callback', ['state' => $query['state'], 'code' => 'codigo-hubspot']))
        ->assertRedirect(route('integrations.hubspot.show'))
        ->assertSessionHas('success');

    $connection = HubSpotConnection::withoutOrganizationScope()->sole();

    expect($connection->portal_id)->toBe($newPortal)
        ->and($connection->refresh_token)->toBe('HUBSPOT-REFRESH-NOVO');

    // O par anterior some do banco; precisa ter sido revogado no HubSpot.
    Http::assertSent(fn (Request $request): bool => $request->url() === REVIEW_3G_HUBSPOT_REVOKE_URL
        && ($request->data()['token'] ?? null) === 'HUBSPOT-REFRESH-SENTINELA-777001');
})->with([
    'outro portal' => 777002,
    'o mesmo portal' => 777001,
]);
