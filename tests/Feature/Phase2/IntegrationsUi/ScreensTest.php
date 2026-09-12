<?php

use App\Enums\MembershipRole;
use App\Enums\Permission;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../RestHooks/Support/RestHookHelpers.php';
require_once __DIR__.'/../Permissions/Support/PermissionHelpers.php';

/*
|--------------------------------------------------------------------------
| Telas de API e integrações — flags, acesso e Documentação (D-PLAT)
|--------------------------------------------------------------------------
| Flags desligadas: exatamente o placeholder da Fase 1. Ligadas: telas reais só para quem
| tem `manage_integrations`.
*/

test('flags desligadas: o placeholder da Fase 1, igual, para qualquer papel', function (MembershipRole $role): void {
    ['organization' => $organization] = createOrganizationWithOwner();
    $user = attachMember($organization, $role);
    actingAsMember($user, $organization);

    $this->get(route('integrations.index'))->assertInertia(fn (Assert $page) => $page
        ->component('integrations/index')
        ->where('feature', 'api_integrations')
        ->where('title', 'API e integrações')
        ->where('subtitle', 'Automatize envios e receba eventos por webhooks.')
        ->where('support_email', (string) config('assinavelox.support_email'))
        ->missing('navigation'));
})->with([MembershipRole::Admin, MembershipRole::Member]);

test('flags desligadas: chaves e logs redirecionam; criar e revogar chave dão 404', function (): void {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('integrations.keys'))->assertRedirect(route('integrations.index'));
    $this->get(route('integrations.logs'))->assertRedirect(route('integrations.index'));
    $this->post(route('integrations.keys.store'), ['name' => 'ERP', 'abilities' => ['envelopes:read']])->assertNotFound();
});

test('API ligada: o proprietário vê a Documentação com as rotas lidas do roteador', function (): void {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    apiEnable($organization);
    actingAsMember($owner, $organization);

    $page = inertiaPage(inertiaGet(route('integrations.index')));
    $props = $page['props'];
    $names = array_column($props['endpoints'], 'name');

    expect($page['component'])->toBe('integrations/docs')
        ->and($props['navigation'])->toBe(['docs' => true, 'keys' => true, 'webhooks' => false, 'logs' => true, 'rest_hooks' => false])
        ->and($props['base_url'])->toEndWith('/api/v1')
        ->and($props['openapi']['ui_url'])->toEndWith('/docs/api')
        ->and($names)->toContain('api.v1.envelopes.store', 'api.v1.envelopes.send')
        ->and($names)->not->toContain('api.v1.webhook_subscriptions.store');

    $store = collect($props['endpoints'])->firstWhere('name', 'api.v1.envelopes.store');
    expect($store)->toMatchArray(['method' => 'POST', 'path' => '/envelopes', 'abilities' => ['envelopes:write'], 'idempotency' => 'required']);
});

test('REST Hooks aparecem na Documentação só com a flag ligada', function (): void {
    ['organization' => $organization, 'owner' => $owner] = restHooksOrg();
    actingAsMember($owner, $organization);

    $props = inertiaPage(inertiaGet(route('integrations.index')))['props'];

    expect($props['navigation']['rest_hooks'])->toBeTrue()
        ->and(array_column($props['endpoints'], 'name'))->toContain('api.v1.webhook_subscriptions.store', 'api.v1.webhook_events.sample')
        ->and($props['sample_event']['type'])->toBe('recipient.signed')
        ->and($props['sample_event']['data']['recipient']['action'])->toBe('electronic_acceptance');
});

test('só webhooks ligados: Documentação sem rotas da API; chaves e logs redirecionam', function (): void {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    webhooksOn($organization);
    actingAsMember($owner, $organization);

    $props = inertiaPage(inertiaGet(route('integrations.index')))['props'];

    expect($props['navigation'])->toBe(['docs' => true, 'keys' => false, 'webhooks' => true, 'logs' => false, 'rest_hooks' => false])
        ->and($props['endpoints'])->toBe([])
        ->and($props['openapi'])->toBeNull();

    $this->get(route('integrations.keys'))->assertRedirect(route('integrations.index'));
    $this->get(route('integrations.logs'))->assertRedirect(route('integrations.index'));
});

test('com a flag ligada, quem não tem manage_integrations recebe 403 em todas as telas', function (): void {
    ['organization' => $organization] = restHooksOrg();
    $member = attachMember($organization, MembershipRole::Member);
    actingAsMember($member, $organization);

    $this->get(route('integrations.index'))->assertForbidden();
    $this->get(route('integrations.keys'))->assertForbidden();
    $this->get(route('integrations.logs'))->assertForbidden();
    $this->get(route('integrations.webhooks.index'))->assertForbidden();
    $this->post(route('integrations.keys.store'), ['name' => 'ERP', 'abilities' => ['envelopes:read']])->assertForbidden();
});

test('função personalizada com manage_integrations abre as telas', function (): void {
    ['organization' => $organization] = restHooksOrg();
    enableCustomRoles();
    $role = createCustomRole($organization, 'Integrações', [Permission::ManageIntegrations]);
    $user = attachWithCustomRole($organization, $role);
    actingAsMember($user, $organization);

    expect(inertiaPage(inertiaGet(route('integrations.index')))['component'])->toBe('integrations/docs')
        ->and(inertiaPage(inertiaGet(route('integrations.keys')))['component'])->toBe('integrations/keys')
        ->and(inertiaPage(inertiaGet(route('integrations.logs')))['component'])->toBe('integrations/logs');
});
