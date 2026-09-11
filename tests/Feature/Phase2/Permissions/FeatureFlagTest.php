<?php

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Team;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/PermissionHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('com a flag desligada (padrão) os cadastros de funções, times e pastas respondem 403', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $role = createCustomRole($organization, 'Criada antes', [Permission::CreateEnvelopes]);
    $folder = folderIn($organization, 'Locação');
    $member = attachMember($organization, MembershipRole::Member);

    actingAsMember($owner, $organization);

    $this->post(route('roles.store'), ['name' => 'Nova', 'permissions' => []])->assertForbidden();
    $this->patch(route('roles.update', $role), ['name' => 'Outra'])->assertForbidden();
    $this->delete(route('roles.destroy', $role))->assertForbidden();
    $this->put(route('roles.folders', $role), ['folders' => []])->assertForbidden();
    $this->post(route('teams.store'), ['name' => 'Time'])->assertForbidden();
    $this->put(route('members.folders', membershipOf($member, $organization)), ['folders' => [['folder' => $folder->ulid, 'level' => 'view']]])->assertForbidden();

    expect(Role::forOrganization($organization)->where('is_system', false)->count())->toBe(1)
        ->and(Team::forOrganization($organization)->count())->toBe(0);
});

test('com a flag desligada a tela esconde funções personalizadas e não permite atribuí-las', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $role = createCustomRole($organization, 'Escondida', [Permission::CreateEnvelopes]);
    $member = attachMember($organization, MembershipRole::Member);
    $folder = folderIn($organization, 'Locação');

    actingAsMember($owner, $organization);

    $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
        ->where('custom_roles_enabled', false)
        ->has('role_catalog', 3)
        ->where('role_catalog.0.key', 'owner')
        ->where('role_catalog.1.key', 'admin')
        ->where('role_catalog.2.key', 'member')
        ->where('teams', [])
        ->where('can.create_role', false)
        ->has('roles', 3)
        ->has('permission_matrix'));

    $this->patch(route('members.update', membershipOf($member, $organization)), ['role_id' => $role->ulid])
        ->assertSessionHasErrors('role_id');

    $this->post(route('invitations.store'), [
        'emails' => 'novo@exemplo.com.br',
        'role' => 'member',
        'folders' => [['folder' => $folder->ulid, 'level' => 'view']],
    ])->assertSessionHasErrors('folders');
});

test('a flag exige o interruptor global; com ele ligado o plano decide (sem a chave, vale o global)', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $plan = Plan::free();

    expect(Permissions::customRolesEnabled($organization))->toBeFalse();

    enableCustomRoles();
    expect(Permissions::customRolesEnabled($organization))->toBeTrue();

    $plan->update(['features' => [...($plan->features ?? []), 'custom_roles' => false]]);
    expect(Permissions::customRolesEnabled($organization->fresh()))->toBeFalse();

    // Integração I-2A: o interruptor global desligado vence o plano (roadmap §1 T8).
    enableCustomRoles(false);
    $plan->update(['features' => [...($plan->features ?? []), 'custom_roles' => true]]);
    expect(Permissions::customRolesEnabled($organization->fresh()))->toBeFalse();

    enableCustomRoles();
    expect(Permissions::customRolesEnabled($organization->fresh()))->toBeTrue();
});

test('com a flag ligada a tela traz o catálogo, as funções personalizadas e os times', function () {
    enableCustomRoles();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    createCustomRole($organization, 'Revisor', [Permission::CreateEnvelopes]);

    actingAsMember($owner, $organization);

    $this->get(route('members.index', ['tab' => 'roles']))->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'roles')
        ->where('custom_roles_enabled', true)
        ->has('role_catalog', 4)
        ->where('role_catalog.3.name', 'Revisor')
        ->where('role_catalog.3.is_system', false)
        ->where('role_catalog.3.permissions', ['create_envelopes'])
        ->where('role_catalog.3.can.update', true)
        ->where('role_catalog.0.can.update', false)
        ->where('role_catalog.0.can.assign', false)
        ->where('can.create_role', true)
        ->where('can.create_team', true)
        ->has('permission_catalog', 5));
});
