<?php

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\ApiToken;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../RestHooks/Support/RestHookHelpers.php';
require_once __DIR__.'/../Permissions/Support/PermissionHelpers.php';

/*
|--------------------------------------------------------------------------
| Integrações → Chaves (D-PLAT; contrato docs/fase-2/api-v1.md §14)
|--------------------------------------------------------------------------
| O texto do token aparece UMA vez, na resposta da criação — nunca na sessão, na listagem ou
| em outra requisição. Anti-escalada e isolamento vêm do ApiTokenManager.
*/

beforeEach(function (): void {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
});

test('criar mostra o texto da chave uma única vez, só na resposta da criação', function (): void {
    $response = inertiaPost(route('integrations.keys.store'), [
        'name' => 'Integração ERP',
        'abilities' => ['envelopes:read', 'documents:read'],
        'expires_at' => Carbon::now()->addDays(90)->toIso8601String(),
    ]);

    $page = inertiaPage($response);
    $revealed = $page['props']['revealed_token'];
    $plain = $revealed['token'];
    $token = ApiToken::withoutOrganizationScope()->sole();

    expect($page['component'])->toBe('integrations/keys')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($revealed['id'])->toBe($token->ulid)
        ->and($revealed['name'])->toBe('Integração ERP')
        ->and($token->abilityValues())->toBe(['envelopes:read', 'documents:read'])
        ->and($token->expires_at?->isFuture())->toBeTrue()
        // Nada na sessão (sem flash) e o banco só tem o hash.
        ->and(json_encode(session()->all()))->not->toContain(explode('|', $plain, 2)[1])
        ->and($token->token)->toBe(hash('sha256', explode('|', $plain, 2)[1]));

    // A chave funciona na API.
    $this->getJson('/api/v1/envelopes', apiHeaders($plain))->assertOk();

    // A requisição da API troca o usuário do guard no processo de teste; volta à sessão web.
    actingAsMember($this->owner, $this->organization);

    // A próxima visita já não traz o texto; a listagem só mostra o prefixo.
    $again = inertiaPage(inertiaGet(route('integrations.keys')));
    $json = json_encode($again['props']);

    expect($again['props']['revealed_token'])->toBeNull()
        ->and($json)->not->toContain(explode('|', $plain, 2)[1])
        ->and($json)->not->toContain($token->token)
        ->and($again['props']['tokens'][0])->toMatchArray([
            'id' => $token->ulid,
            'name' => 'Integração ERP',
            'state' => 'active',
            'subscriptions' => 0,
        ])
        ->and($again['props']['tokens'][0]['prefix'])->toEndWith('…')
        ->and($again['props']['limits']['active'])->toBe(1);
});

test('validação: nome e permissões obrigatórios voltam como erro, sem criar nada', function (): void {
    $this->from(route('integrations.keys'))
        ->post(route('integrations.keys.store'), ['name' => '', 'abilities' => []])
        ->assertRedirect(route('integrations.keys'))
        ->assertSessionHasErrors(['name', 'abilities']);

    expect(ApiToken::withoutOrganizationScope()->count())->toBe(0);
});

test('anti-escalada: quem não pode enviar documentos não vê nem concede envelopes:send', function (): void {
    enableCustomRoles();
    $role = createCustomRole($this->organization, 'Integrações', [Permission::ManageIntegrations]);
    $user = attachWithCustomRole($this->organization, $role);
    actingAsMember($user, $this->organization);

    $abilities = collect(inertiaPage(inertiaGet(route('integrations.keys')))['props']['abilities'])->keyBy('value');

    expect($abilities['envelopes:send']['grantable'])->toBeFalse()
        ->and($abilities['envelopes:write']['grantable'])->toBeFalse()
        ->and($abilities['envelopes:read']['grantable'])->toBeTrue()
        ->and($abilities['webhooks:manage']['grantable'])->toBeTrue();

    $this->from(route('integrations.keys'))
        ->post(route('integrations.keys.store'), ['name' => 'Tentativa', 'abilities' => ['envelopes:send']])
        ->assertSessionHasErrors('abilities');

    expect(ApiToken::withoutOrganizationScope()->count())->toBe(0);
});

test('revogar: a chave para de autenticar na hora; revogar de novo avisa', function (): void {
    $plain = apiIssueToken($this->organization, $this->owner, ['envelopes:read']);
    $token = ApiToken::withoutOrganizationScope()->sole();

    $this->delete(route('integrations.keys.destroy', ['apiToken' => $token->ulid]))
        ->assertRedirect(route('integrations.keys'))
        ->assertSessionHas('success');

    expect($token->fresh()->revoked_at)->not->toBeNull()
        ->and($token->fresh()->revoked_by_user_id)->toBe($this->owner->id);
    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($plain)), 401, 'unauthenticated');

    $this->delete(route('integrations.keys.destroy', ['apiToken' => $token->ulid]))
        ->assertSessionHas('success', 'Esta chave já estava revogada.');
});

test('isolamento: chave de outra organização dá 404 e continua valendo', function (): void {
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    apiEnable($other);
    $foreignPlain = apiIssueToken($other, $otherOwner, ['envelopes:read']);
    $foreign = ApiToken::withoutOrganizationScope()->where('organization_id', $other->id)->sole();

    $this->delete(route('integrations.keys.destroy', ['apiToken' => $foreign->ulid]))->assertNotFound();

    expect($foreign->fresh()->revoked_at)->toBeNull()
        ->and(inertiaPage(inertiaGet(route('integrations.keys')))['props']['tokens'])->toBe([]);
    $this->getJson('/api/v1/envelopes', apiHeaders($foreignPlain))->assertOk();
});

test('sem manage_integrations: 403 ao criar e ao revogar', function (): void {
    $plain = apiIssueToken($this->organization, $this->owner, ['envelopes:read']);
    $token = ApiToken::withoutOrganizationScope()->sole();

    $admin = attachMember($this->organization, MembershipRole::Member);
    actingAsMember($admin, $this->organization);

    $this->post(route('integrations.keys.store'), ['name' => 'ERP', 'abilities' => ['envelopes:read']])->assertForbidden();
    $this->delete(route('integrations.keys.destroy', ['apiToken' => $token->ulid]))->assertForbidden();

    expect($token->fresh()->revoked_at)->toBeNull()
        ->and(ApiToken::withoutOrganizationScope()->count())->toBe(1);
    $this->getJson('/api/v1/envelopes', apiHeaders($plain))->assertOk();
});
