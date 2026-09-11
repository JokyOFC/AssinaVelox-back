<?php

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\FolderPermission;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/PermissionHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    enableCustomRoles();
});

/**
 * @return array<string, mixed>
 */
function twoOrganizationsWithAccess(): array
{
    $mine = createOrganizationWithOwner(['name' => 'Minha Org']);
    $other = createOrganizationWithOwner(['name' => 'Outra Org']);

    $foreignRole = createCustomRole($other['organization'], 'Função alheia', [Permission::CreateEnvelopes]);
    $foreignTeam = new Team;
    $foreignTeam->forceFill(['organization_id' => $other['organization']->id, 'name' => 'Time alheio'])->save();
    $foreignFolder = folderIn($other['organization'], 'Pasta alheia');

    $myRole = createCustomRole($mine['organization'], 'Minha função', [Permission::CreateEnvelopes]);
    $myMember = attachMember($mine['organization'], MembershipRole::Member);

    return compact('mine', 'other', 'foreignRole', 'foreignTeam', 'foreignFolder', 'myRole', 'myMember');
}

test('funções e times de outra organização devolvem 404', function () {
    $ctx = twoOrganizationsWithAccess();

    actingAsMember($ctx['mine']['owner'], $ctx['mine']['organization']);

    $this->patch(route('roles.update', $ctx['foreignRole']), ['name' => 'Invadida'])->assertNotFound();
    $this->delete(route('roles.destroy', $ctx['foreignRole']))->assertNotFound();
    $this->put(route('roles.folders', $ctx['foreignRole']), ['folders' => []])->assertNotFound();
    $this->patch(route('teams.update', $ctx['foreignTeam']), ['name' => 'Invadido'])->assertNotFound();
    $this->delete(route('teams.destroy', $ctx['foreignTeam']))->assertNotFound();
    $this->put(route('members.folders', $ctx['other']['membership']), ['folders' => []])->assertNotFound();

    expect($ctx['foreignRole']->fresh()->name)->toBe('Função alheia')
        ->and($ctx['foreignTeam']->fresh()->name)->toBe('Time alheio');
});

test('ids de pasta, membership e função de outra organização são ignorados ou rejeitados', function () {
    $ctx = twoOrganizationsWithAccess();
    $organization = $ctx['mine']['organization'];

    actingAsMember($ctx['mine']['owner'], $organization);

    $this->put(route('roles.folders', $ctx['myRole']), [
        'folders' => [['folder' => $ctx['foreignFolder']->ulid, 'level' => 'manage']],
    ])->assertSessionHasNoErrors();
    expect(FolderPermission::withoutOrganizationScope()->count())->toBe(0);

    $this->post(route('teams.store'), [
        'name' => 'Time misto',
        'members' => [$ctx['other']['membership']->id, membershipOf($ctx['myMember'], $organization)->id],
    ])->assertSessionHasNoErrors();
    $team = Team::forOrganization($organization)->where('name', 'Time misto')->firstOrFail();
    expect($team->memberships()->pluck('memberships.id')->all())->toBe([membershipOf($ctx['myMember'], $organization)->id]);

    $this->patch(route('members.update', membershipOf($ctx['myMember'], $organization)), ['role_id' => $ctx['foreignRole']->ulid])
        ->assertSessionHasErrors('role_id');
    expect(membershipOf($ctx['myMember'], $organization)->role_id)->toBeNull();
});

test('a tela de usuários lista só funções e times da organização corrente', function () {
    $ctx = twoOrganizationsWithAccess();

    actingAsMember($ctx['mine']['owner'], $ctx['mine']['organization']);

    $props = $this->get(route('members.index'))->viewData('page')['props'];

    expect(collect($props['role_catalog'])->pluck('name')->all())
        ->toBe(['Proprietário', 'Administrador', 'Operador', 'Minha função'])
        ->and($props['teams'])->toBe([]);
});

test('papéis de sistema são por organização', function () {
    $ctx = twoOrganizationsWithAccess();

    actingAsMember($ctx['other']['owner'], $ctx['other']['organization']);
    $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
        ->has('role_catalog', 4)
        ->where('role_catalog.3.name', 'Função alheia'));
});
