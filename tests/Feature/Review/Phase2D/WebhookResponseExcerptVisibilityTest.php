<?php

use App\Enums\AuditEventType;
use App\Enums\Permission;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Phase2/Webhooks/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 2D (webhooks) — trecho da resposta vaza o corpo que a tela esconde
|--------------------------------------------------------------------------
| WebhookPresenter::deliveryDetail omite `payload` (payload_hidden) para quem não vê o
| envelope, mas devolve `history[].response_excerpt` sem filtro. Receptores que ecoam o corpo
| (comum em n8n/Make/webhook.site e em respostas de erro) fazem o trecho carregar o id e o
| código do envelope que a regra de visibilidade (docs/fase-2/webhooks.md §7) esconde.
*/

if (! function_exists('reviewWebhookPlanFeature')) {
    function reviewWebhookPlanFeature(Organization $organization, string $flag): void
    {
        config()->set('assinavelox.features.'.$flag, true);
        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan !== null) {
            $features = (array) ($plan->features ?? []);
            $features[$flag] = true;
            $plan->forceFill(['features' => $features])->save();
        }
    }
}

it('com o corpo oculto, o trecho da resposta guardado no histórico não reexpõe o envelope', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    reviewWebhookPlanFeature($organization, 'custom_roles');
    ['endpoint' => $endpoint] = makeEndpoint($organization, $owner);
    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);

    // Receptor que ecoa o corpo recebido.
    Http::fake(fn (Request $request) => Http::response($request->body(), 200));
    recordAudit($envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    $role = createCustomRole($organization, 'Só integrações', [Permission::ManageIntegrations]);
    $integrator = attachWithCustomRole($organization, $role);
    actingAsMember($integrator, $organization);

    $response = $this->getJson(route('integrations.webhooks.deliveries.show', ['webhookEndpoint' => $endpoint->ulid, 'delivery' => $delivery->ulid]))
        ->assertOk()
        ->assertJsonPath('payload', null)
        ->assertJsonPath('payload_hidden', true);

    $history = json_encode($response->json('history'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($history)->not->toContain($envelope->ulid)
        ->and($history)->not->toContain($envelope->display_code);
});
