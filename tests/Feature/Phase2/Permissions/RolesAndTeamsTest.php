<?php

use App\Enums\AuditEventType;
use App\Enums\FolderAccessLevel;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\FolderPermission;
use App\Models\MembershipInvitation;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\PermissionsInvitationGrants;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/PermissionHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    enableCustomRoles();
});

test('owner cria, edita e exclui uma função personalizada, com trilha', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    actingAsMember($owner, $organization);

    $this->post(route('roles.store'), [
        'name' => 'Gerente',
        'description' => 'Gerencia documentos e pastas',
        'permissions' => ['create_envelopes', 'send_envelopes', 'view_all_envelopes', 'manage_folders'],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $role = Role::forOrganization($organization)->where('name', 'Gerente')->firstOrFail();
    expect($role->is_system)->toBeFalse()
        ->and($role->grantedPermissionValues())->toBe(['create_envelopes', 'send_envelopes', 'view_all_envelopes', 'manage_folders']);

    $this->post(route('roles.store'), ['name' => 'gerente', 'permissions' => []])->assertSessionHasErrors('name');
    $this->post(route('roles.store'), ['name' => 'operador', 'permissions' => []])->assertSessionHasErrors('name');

    $this->patch(route('roles.update', $role), ['permissions' => ['create_envelopes', 'send_envelopes', 'cancel_any_envelope']])
        ->assertSessionHasNoErrors();
    expect($role->fresh()->grantedPermissionValues())->toBe(['create_envelopes', 'send_envelopes', 'cancel_any_envelope']);

    $updated = AuditEvent::query()->where('event_type', AuditEventType::RoleUpdated->value)->firstOrFail();
    expect($updated->payload['added'])->toBe(['cancel_any_envelope'])
        ->and($updated->payload['removed'])->toEqualCanonicalizing(['view_all_envelopes', 'manage_folders'])
        ->and($updated->envelope_id)->toBeNull();

    // Atribui a função; para excluí-la é preciso reatribuir quem a tem antes.
    $membership = membershipOf($member, $organization);
    $this->patch(route('members.update', $membership), ['role_id' => $role->ulid])->assertSessionHasNoErrors();
    expect($membership->fresh()->role_id)->toBe($role->id)
        ->and($membership->fresh()->role)->toBe(MembershipRole::Member)
        ->and($membership->fresh()->roleLabel())->toBe('Gerente');

    $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
        ->where('members.1.role_label', 'Gerente')
        ->where('members.1.role_id', $role->ulid));

    // Revisão adversarial (RoleDeletionPrivilegeGainTest): excluir não passa ninguém para
    // Operador automaticamente — uma função pode ser mais restrita que Operador.
    $this->delete(route('roles.destroy', $role))->assertSessionHasErrors('role');
    expect(Role::forOrganization($organization)->whereKey($role->id)->exists())->toBeTrue()
        ->and($membership->fresh()->role_id)->toBe($role->id);

    $this->patch(route('members.update', $membership), ['role' => 'member'])->assertSessionHasNoErrors();
    $this->delete(route('roles.destroy', $role))->assertSessionHasNoErrors()->assertRedirect();
    expect(Role::forOrganization($organization)->whereKey($role->id)->exists())->toBeFalse()
        ->and($membership->fresh()->role_id)->toBeNull()
        ->and($membership->fresh()->role)->toBe(MembershipRole::Member);

    expect(AuditEvent::query()->pluck('event_type')->map(fn ($t) => $t->value)->all())
        ->toContain('role.created', 'role.updated', 'membership.role_changed', 'role.deleted');
});

test('a função personalizada concede exatamente as suas permissões', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $reader = createCustomRole($organization, 'Somente leitura', [Permission::ViewAllEnvelopes, Permission::ExportData]);
    $user = attachWithCustomRole($organization, $reader);
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();

    actingAsMember($user, $organization);

    $this->get(route('envelopes.show', $envelope))->assertOk();
    $this->post(route('envelopes.cancel', $envelope), ['reason' => 'x'])->assertForbidden();
    $this->post(route('envelopes.duplicate', $envelope))->assertForbidden();
    $this->get(route('envelopes.create'))->assertForbidden();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('organization.permissions.view_all_envelopes', true)
        ->where('organization.permissions.create_envelopes', false)
        ->where('organization.permissions.manage_members', false));
});

test('owner cria e edita times com participantes e pastas; excluir o time retira o acesso', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $a = attachMember($organization, MembershipRole::Member);
    $b = attachMember($organization, MembershipRole::Member);
    $folder = folderIn($organization, 'Vendas');
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create(['folder_id' => $folder->id]);

    actingAsMember($owner, $organization);

    $this->post(route('teams.store'), [
        'name' => 'Comercial',
        'members' => [membershipOf($a, $organization)->id],
        'folders' => [['folder' => $folder->ulid, 'level' => 'manage']],
    ])->assertSessionHasNoErrors();

    $team = Team::forOrganization($organization)->where('name', 'Comercial')->firstOrFail();
    expect(FolderPermission::query()->where('team_id', $team->id)->value('level'))->toBe(FolderAccessLevel::Manage);

    actingAsMember($a, $organization);
    $this->get(route('envelopes.show', $envelope))->assertOk();
    actingAsMember($b, $organization);
    $this->get(route('envelopes.show', $envelope))->assertForbidden();

    actingAsMember($owner, $organization);
    $this->patch(route('teams.update', $team), ['members' => [membershipOf($b, $organization)->id]])->assertSessionHasNoErrors();

    actingAsMember($a, $organization);
    $this->get(route('envelopes.show', $envelope))->assertForbidden();
    actingAsMember($b, $organization);
    $this->get(route('envelopes.show', $envelope))->assertOk();

    actingAsMember($owner, $organization);
    $this->get(route('members.index', ['tab' => 'teams']))->assertInertia(fn (Assert $page) => $page
        ->has('teams', 1)
        ->where('teams.0.name', 'Comercial')
        ->where('teams.0.member_ids', [(string) membershipOf($b, $organization)->id])
        ->where('teams.0.folders.0.name', 'Vendas'));

    $this->delete(route('teams.destroy', $team))->assertSessionHasNoErrors();
    expect(FolderPermission::query()->where('team_id', $team->id)->exists())->toBeFalse();

    actingAsMember($b, $organization);
    $this->get(route('envelopes.show', $envelope))->assertForbidden();
});

test('owner define as pastas com acesso direto de um membro', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);
    $folder = folderIn($organization, 'Locação');
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create(['folder_id' => $folder->id]);

    actingAsMember($owner, $organization);
    $this->put(route('members.folders', membershipOf($member, $organization)), [
        'folders' => [['folder' => $folder->ulid, 'level' => 'view']],
    ])->assertSessionHasNoErrors();

    $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
        ->where('members.1.folders.0.name', 'Locação')
        ->where('members.1.folders.0.level', 'view'));

    actingAsMember($member, $organization);
    $this->get(route('envelopes.show', $envelope))->assertOk();

    expect(AuditEvent::query()->where('event_type', AuditEventType::FolderAccessUpdated->value)->firstOrFail()->payload['folders'])
        ->toBe([$folder->ulid]);
});

test('convite com função personalizada e pastas aplica ambas no aceite', function () {
    Notification::fake();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    Plan::free()->update(['user_quota' => 10]);
    $role = createCustomRole($organization, 'Corretor', [Permission::CreateEnvelopes, Permission::SendEnvelopes]);
    $folder = folderIn($organization, 'Locação');

    actingAsMember($owner, $organization);
    $this->post(route('invitations.store'), [
        'emails' => 'corretor@exemplo.com.br',
        'role_id' => $role->ulid,
        'folders' => [['folder' => $folder->ulid, 'level' => 'view']],
    ])->assertSessionHasNoErrors();

    $invitation = MembershipInvitation::query()->where('email', 'corretor@exemplo.com.br')->firstOrFail();
    expect($invitation->role)->toBe(MembershipRole::Member)
        ->and($invitation->getAttribute('role_id'))->toBe($role->id);

    $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
        ->where('invitations.0.role_label', 'Corretor')
        ->where('invitations.0.role_id', $role->ulid));

    // Aceite com um token conhecido (o e-mail real carrega o token bruto).
    [$accepting, $token] = pendingInvitationWithToken([
        'organization_id' => $organization->id,
        'invited_by_user_id' => $owner->id,
        'email' => 'outro.corretor@exemplo.com.br',
        'role' => MembershipRole::Member,
    ]);
    PermissionsInvitationGrants::store($accepting, $role, [$folder->id => FolderAccessLevel::View]);

    $invitee = User::factory()->create(['email' => 'outro.corretor@exemplo.com.br']);
    $this->actingAs($invitee)->get(route('invitations.accept', ['token' => $token]))
        ->assertInertia(fn (Assert $page) => $page->where('invitation.role_label', 'Corretor'));
    $this->actingAs($invitee)->post(route('invitations.accept.store', ['token' => $token]))->assertRedirect(route('dashboard'));

    $membership = membershipOf($invitee, $organization);
    expect($membership->role_id)->toBe($role->id)
        ->and($membership->roleLabel())->toBe('Corretor')
        ->and(FolderPermission::query()->where('membership_id', $membership->id)->value('folder_id'))->toBe($folder->id);
});
