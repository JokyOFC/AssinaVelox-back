<?php

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEndpointPausedNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Isolamento e mínimo privilégio — docs/fase-2/webhooks.md §7
|--------------------------------------------------------------------------
*/

function planFeature(Organization $organization, string $flag, bool $enabled = true): void
{
    config()->set('assinavelox.features.'.$flag, $enabled);
    $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

    if ($plan !== null) {
        $features = (array) ($plan->features ?? []);
        $features[$flag] = $enabled;
        $plan->forceFill(['features' => $features])->save();
    }
}

it('endpoint de outra organização é inacessível (404) em todas as rotas', function (): void {
    ['organization' => $orgA, 'owner' => $ownerA] = webhookOrg();
    ['endpoint' => $endpoint] = makeEndpoint($orgA, $ownerA);
    ['envelope' => $envelope] = webhookEnvelope($orgA, $ownerA);
    Http::fake(['*' => Http::response('ok', 200)]);
    recordAudit($envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    ['organization' => $orgB, 'owner' => $ownerB] = createOrganizationWithOwner();
    webhooksOn($orgB);
    actingAsMember($ownerB, $orgB);

    $params = ['webhookEndpoint' => $endpoint->ulid];
    $deliveryParams = [...$params, 'delivery' => $delivery->ulid];

    $this->get(route('integrations.webhooks.show', $params))->assertNotFound();
    $this->patch(route('integrations.webhooks.update', $params), ['events' => ['*']])->assertNotFound();
    $this->delete(route('integrations.webhooks.destroy', $params))->assertNotFound();
    $this->post(route('integrations.webhooks.pause', $params))->assertNotFound();
    $this->post(route('integrations.webhooks.resume', $params))->assertNotFound();
    $this->post(route('integrations.webhooks.secret.rotate', $params))->assertNotFound();
    $this->post(route('integrations.webhooks.test', $params))->assertNotFound();
    $this->getJson(route('integrations.webhooks.deliveries.show', $deliveryParams))->assertNotFound();
    $this->post(route('integrations.webhooks.deliveries.resend', $deliveryParams))->assertNotFound();

    expect(inertiaPage(inertiaGet(route('integrations.webhooks.index')))['props']['endpoints'])->toBe([]);

    expect($endpoint->fresh()->is_active)->toBeTrue()
        ->and(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(1);
});

it('entrega de outro endpoint não é aberta pelo endpoint errado (binding escopado)', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    ['endpoint' => $first] = makeEndpoint($organization, $owner, ['envelope.sent']);
    ['endpoint' => $second] = makeEndpoint($organization, $owner, ['envelope.completed']);
    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);
    Http::fake(['*' => Http::response('ok', 200)]);
    recordAudit($envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();
    actingAsMember($owner, $organization);

    $this->getJson(route('integrations.webhooks.deliveries.show', ['webhookEndpoint' => $second->ulid, 'delivery' => $delivery->ulid]))
        ->assertNotFound();
    $this->getJson(route('integrations.webhooks.deliveries.show', ['webhookEndpoint' => $first->ulid, 'delivery' => $delivery->ulid]))
        ->assertOk();
});

it('quem não tem manage_integrations recebe 403', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    ['endpoint' => $endpoint] = makeEndpoint($organization, $owner);
    $operator = attachMember($organization, MembershipRole::Member);
    actingAsMember($operator, $organization);

    $this->get(route('integrations.webhooks.index'))->assertForbidden();
    $this->post(route('integrations.webhooks.store'), ['url' => WEBHOOK_TEST_URL, 'events' => ['*']])->assertForbidden();
    $this->get(route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid]))->assertForbidden();
    $this->post(route('integrations.webhooks.secret.rotate', ['webhookEndpoint' => $endpoint->ulid]))->assertForbidden();
    $this->delete(route('integrations.webhooks.destroy', ['webhookEndpoint' => $endpoint->ulid]))->assertForbidden();

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(1);
});

it('administrador também gerencia (papel de sistema com manage_integrations)', function (): void {
    ['organization' => $organization] = webhookOrg();
    $admin = attachMember($organization, MembershipRole::Admin);
    actingAsMember($admin, $organization);

    $this->post(route('integrations.webhooks.store'), ['url' => WEBHOOK_TEST_URL, 'events' => ['*']])->assertRedirect();

    expect(WebhookEndpoint::withoutOrganizationScope()->sole()->created_by_user_id)->toBe($admin->id);
});

it('eventos de uma organização nunca vão para endpoints de outra', function (): void {
    ['organization' => $orgA, 'owner' => $ownerA] = webhookOrg();
    ['organization' => $orgB, 'owner' => $ownerB] = createOrganizationWithOwner();
    webhooksOn($orgB);
    ['endpoint' => $endpointA] = makeEndpoint($orgA, $ownerA);
    ['endpoint' => $endpointB] = makeEndpoint($orgB, $ownerB);
    ['envelope' => $envelopeA] = webhookEnvelope($orgA, $ownerA);
    Http::fake(['*' => Http::response('ok', 200)]);

    recordAudit($envelopeA, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->webhook_endpoint_id)->toBe($endpointA->id)
        ->and($delivery->organization_id)->toBe($orgA->id)
        ->and($delivery->payload)->not->toContain($orgB->ulid);
});

it('responsável que perde manage_integrations: endpoint pausado, nada sai e os administradores são avisados', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    $admin = attachMember($organization, MembershipRole::Admin);
    ['endpoint' => $endpoint] = makeEndpoint($organization, $admin);
    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);
    Notification::fake();
    Http::fake();

    $membership = Membership::query()->where('organization_id', $organization->id)->where('user_id', $admin->id)->firstOrFail();
    $membership->forceFill(['role' => MembershipRole::Member])->save();
    $membership->forgetPermissions();

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    expect(WebhookDelivery::withoutOrganizationScope()->count())->toBe(0)
        ->and($endpoint->fresh()->is_active)->toBeFalse()
        ->and($endpoint->fresh()->paused_reason)->toBe(WebhookEndpoint::PAUSED_CREATOR_WITHOUT_ACCESS);
    Http::assertNothingSent();
    Notification::assertSentTo($owner, WebhookEndpointPausedNotification::class);

    // Quem reativa passa a responder pelo endpoint.
    actingAsMember($owner, $organization);
    $this->post(route('integrations.webhooks.resume', ['webhookEndpoint' => $endpoint->ulid]))->assertSessionHas('success');

    expect($endpoint->fresh()->is_active)->toBeTrue()
        ->and($endpoint->fresh()->created_by_user_id)->toBe($owner->id);
});

it('função personalizada com manage_integrations sem view_all só recebe eventos dos envelopes que vê', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    planFeature($organization, 'custom_roles');
    $role = createCustomRole($organization, 'Integrações', [Permission::ManageIntegrations, Permission::CreateEnvelopes]);
    $integrator = attachWithCustomRole($organization, $role);
    ['endpoint' => $endpoint] = makeEndpoint($organization, $integrator);
    Http::fake(['*' => Http::response('ok', 200)]);

    ['envelope' => $ownersEnvelope] = webhookEnvelope($organization, $owner);
    ['envelope' => $ownEnvelope] = webhookEnvelope($organization, $integrator);

    recordAudit($ownersEnvelope, AuditEventType::EnvelopeSent);
    expect(WebhookDelivery::withoutOrganizationScope()->count())->toBe(0);

    recordAudit($ownEnvelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->envelope_id)->toBe($ownEnvelope->id)
        ->and($delivery->webhook_endpoint_id)->toBe($endpoint->id)
        ->and($endpoint->fresh()->is_active)->toBeTrue();
});

it('integrador sem view_all não redireciona (URL) nem rotaciona o segredo do endpoint de outra pessoa', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    planFeature($organization, 'custom_roles');
    ['endpoint' => $ownersEndpoint] = makeEndpoint($organization, $owner);

    $role = createCustomRole($organization, 'Só integrações', [Permission::ManageIntegrations]);
    $integrator = attachWithCustomRole($organization, $role);
    ['endpoint' => $ownEndpoint] = makeEndpoint($organization, $integrator);
    actingAsMember($integrator, $organization);

    $params = ['webhookEndpoint' => $ownersEndpoint->ulid];
    $originalUrl = $ownersEndpoint->url;
    $originalHint = $ownersEndpoint->secret_hint;

    $this->patch(route('integrations.webhooks.update', $params), ['url' => 'https://outro-destino.example.com/hook'])->assertForbidden();
    $this->post(route('integrations.webhooks.secret.rotate', $params))->assertForbidden();

    // Eventos e descrição continuam editáveis (não redirecionam nada).
    $this->patch(route('integrations.webhooks.update', $params), ['events' => ['envelope.sent']])->assertSessionHas('success');

    expect($ownersEndpoint->fresh()->url)->toBe($originalUrl)
        ->and($ownersEndpoint->fresh()->secret_hint)->toBe($originalHint)
        ->and($ownersEndpoint->fresh()->events)->toBe(['envelope.sent']);

    // O próprio endpoint dela (ela é a responsável) continua sob o controle dela.
    $this->post(route('integrations.webhooks.secret.rotate', ['webhookEndpoint' => $ownEndpoint->ulid]))->assertRedirect();
});

it('na tela, quem não vê o envelope não vê o vínculo nem o corpo da entrega', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    planFeature($organization, 'custom_roles');
    ['endpoint' => $endpoint] = makeEndpoint($organization, $owner);
    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);
    Http::fake(['*' => Http::response('ok', 200)]);
    recordAudit($envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    $role = createCustomRole($organization, 'Só integrações', [Permission::ManageIntegrations]);
    $integrator = attachWithCustomRole($organization, $role);
    actingAsMember($integrator, $organization);

    $this->getJson(route('integrations.webhooks.deliveries.show', ['webhookEndpoint' => $endpoint->ulid, 'delivery' => $delivery->ulid]))
        ->assertOk()
        ->assertJsonPath('envelope', null)
        ->assertJsonPath('payload', null)
        ->assertJsonPath('payload_hidden', true);
});
