<?php

use App\Enums\FolderAccessLevel;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\FolderPermission;
use App\Models\Role;
use App\Models\Team;
use App\Support\CurrentOrganization;
use App\Support\PermissionsSystemRoles;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/Support/PermissionHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    enableCustomRoles();
});

/**
 * "Gente & Gestão": gere funções, times e pastas, mas não vê tudo nem mexe em cobrança.
 *
 * @return array<string, mixed>
 */
function escalationScenario(): array
{
    $ctx = createOrganizationWithOwner();
    $organization = $ctx['organization'];

    $manager = createCustomRole($organization, 'Gente & Gestão', [
        Permission::ManageMembers, Permission::ManageRoles, Permission::ManageTeams, Permission::ManageFolders,
        Permission::CreateEnvelopes, Permission::SendEnvelopes, Permission::ViewReports, Permission::ExportData,
    ]);
    $finance = createCustomRole($organization, 'Financeiro', [Permission::ManageBilling, Permission::ViewReports]);
    $user = attachWithCustomRole($organization, $manager);
    $juridico = folderIn($organization, 'Jurídico');

    return [...$ctx, 'managerRole' => $manager, 'financeRole' => $finance, 'user' => $user, 'juridico' => $juridico];
}

test('não é possível criar função com permissão que o próprio ator não tem', function () {
    $ctx = escalationScenario();

    actingAsMember($ctx['user'], $ctx['organization']);

    $this->post(route('roles.store'), ['name' => 'Cobrança', 'permissions' => ['manage_billing']])
        ->assertSessionHasErrors('permissions');
    expect(Role::forOrganization($ctx['organization'])->where('name', 'Cobrança')->exists())->toBeFalse();

    $this->post(route('roles.store'), ['name' => 'Assistente', 'permissions' => ['create_envelopes']])
        ->assertSessionHasNoErrors();
    expect(Role::forOrganization($ctx['organization'])->where('name', 'Assistente')->firstOrFail()->grantedPermissionValues())
        ->toBe(['create_envelopes']);
});

test('não é possível ampliar a própria função nem editar uma função mais poderosa', function () {
    $ctx = escalationScenario();

    actingAsMember($ctx['user'], $ctx['organization']);

    $this->patch(route('roles.update', $ctx['managerRole']), [
        'permissions' => [...$ctx['managerRole']->grantedPermissionValues(), 'view_all_envelopes'],
    ])->assertSessionHasErrors('permissions');
    expect($ctx['managerRole']->fresh()->grantedPermissionValues())->not->toContain('view_all_envelopes');

    $this->patch(route('roles.update', $ctx['financeRole']), ['name' => 'Financeiro 2'])->assertForbidden();
    $this->delete(route('roles.destroy', $ctx['financeRole']))->assertForbidden();
});

test('permissões exclusivas do proprietário nunca entram numa função, nem pelo owner', function () {
    $ctx = escalationScenario();

    actingAsMember($ctx['owner'], $ctx['organization']);

    $this->post(route('roles.store'), ['name' => 'Quase dono', 'permissions' => ['delete_organization']])
        ->assertSessionHasErrors('permissions.0');
    $this->post(route('roles.store'), ['name' => 'Quase dono', 'permissions' => ['transfer_ownership']])
        ->assertSessionHasErrors('permissions.0');
});

test('papéis de sistema não são editáveis nem removíveis', function () {
    $ctx = escalationScenario();
    $system = PermissionsSystemRoles::ensureFor($ctx['organization']);

    actingAsMember($ctx['owner'], $ctx['organization']);

    foreach (['owner', 'admin', 'member'] as $key) {
        $this->patch(route('roles.update', $system[$key]), ['permissions' => []])->assertForbidden();
        $this->delete(route('roles.destroy', $system[$key]))->assertForbidden();
    }

    expect(Role::forOrganization($ctx['organization'])->where('is_system', true)->count())->toBe(3);
});

test('ninguém atribui função com mais poderes do que tem nem gere quem pode mais', function () {
    $ctx = escalationScenario();
    $organization = $ctx['organization'];
    $system = PermissionsSystemRoles::ensureFor($organization);
    $operator = attachMember($organization, MembershipRole::Member);
    $admin = attachMember($organization, MembershipRole::Admin);

    CurrentOrganization::instance()->set($organization);
    $gate = Gate::forUser($ctx['user']);

    expect($gate->allows('assign', $system['admin']))->toBeFalse()
        ->and($gate->allows('assign', $system['owner']))->toBeFalse()
        ->and($gate->allows('assign', $ctx['financeRole']))->toBeFalse()
        ->and($gate->allows('assign', $system['member']))->toBeTrue()
        ->and($gate->allows('assign', $ctx['managerRole']))->toBeTrue()
        ->and($gate->allows('update', membershipOf($operator, $organization)))->toBeTrue()
        ->and($gate->allows('update', membershipOf($admin, $organization)))->toBeFalse()
        ->and($gate->allows('delete', membershipOf($admin, $organization)))->toBeFalse()
        ->and($gate->allows('update', membershipOf($ctx['owner'], $organization)))->toBeFalse()
        ->and($gate->allows('update', membershipOf($ctx['user'], $organization)))->toBeFalse();
    CurrentOrganization::instance()->clear();
});

test('o último proprietário continua protegido, inclusive contra funções personalizadas', function () {
    $ctx = escalationScenario();
    $ownerMembership = membershipOf($ctx['owner'], $ctx['organization']);

    CurrentOrganization::instance()->set($ctx['organization']);
    expect(Gate::forUser($ctx['user'])->allows('delete', $ownerMembership))->toBeFalse()
        ->and(Gate::forUser($ctx['owner'])->allows('delete', $ownerMembership))->toBeFalse()
        ->and(Gate::forUser($ctx['owner'])->allows('updateStatus', $ownerMembership))->toBeFalse();
    CurrentOrganization::instance()->clear();

    actingAsMember($ctx['owner'], $ctx['organization']);
    $this->patch(route('members.update', $ownerMembership), ['role' => 'admin'])->assertForbidden();
    expect($ownerMembership->fresh()->role)->toBe(MembershipRole::Owner);
});

test('não é possível entrar num time que dá acesso a pasta que o ator ainda não tem', function () {
    $ctx = escalationScenario();
    $organization = $ctx['organization'];
    $self = membershipOf($ctx['user'], $organization);

    $team = new Team;
    $team->forceFill(['organization_id' => $organization->id, 'name' => 'Contencioso'])->save();
    grantFolder($ctx['juridico'], 'team', $team->id);

    actingAsMember($ctx['user'], $organization);

    $this->patch(route('teams.update', $team), ['members' => [$self->id]])->assertSessionHasErrors('members');
    expect($team->memberships()->count())->toBe(0);

    $this->post(route('teams.store'), [
        'name' => 'Meu time',
        'members' => [$self->id],
        'folders' => [['folder' => $ctx['juridico']->ulid, 'level' => 'view']],
    ])->assertSessionHasErrors('members');

    // Sem se incluir, pode criar o time com a pasta (tem manage_folders).
    $this->post(route('teams.store'), [
        'name' => 'Time do jurídico',
        'members' => [],
        'folders' => [['folder' => $ctx['juridico']->ulid, 'level' => 'view']],
    ])->assertSessionHasNoErrors();
});

test('não é possível liberar para a própria função uma pasta que o ator não acessa', function () {
    $ctx = escalationScenario();

    actingAsMember($ctx['user'], $ctx['organization']);

    $this->put(route('roles.folders', $ctx['managerRole']), [
        'folders' => [['folder' => $ctx['juridico']->ulid, 'level' => 'manage']],
    ])->assertSessionHasErrors('folders');

    expect(FolderPermission::query()->where('role_id', $ctx['managerRole']->id)->exists())->toBeFalse();

    // Para outra função (que não inclui o ator), pode.
    $this->put(route('roles.folders', $ctx['financeRole']), [
        'folders' => [['folder' => $ctx['juridico']->ulid, 'level' => 'view']],
    ])->assertSessionHasNoErrors();

    expect(FolderPermission::query()->where('role_id', $ctx['financeRole']->id)->value('level'))->toBe(FolderAccessLevel::View);
});

test('ninguém altera o próprio acesso direto a pastas', function () {
    $ctx = escalationScenario();
    $self = membershipOf($ctx['user'], $ctx['organization']);

    actingAsMember($ctx['user'], $ctx['organization']);

    $this->put(route('members.folders', $self), [
        'folders' => [['folder' => $ctx['juridico']->ulid, 'level' => 'manage']],
    ])->assertForbidden();
});
