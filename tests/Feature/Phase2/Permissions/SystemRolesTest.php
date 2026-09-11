<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\Role;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/PermissionHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('papéis de sistema reproduzem exatamente o mapa de permissões da Fase 1', function () {
    // Mapa `organization.permissions` da Fase 1 (App\Support\Permissions::forRole antigo).
    $phase1 = [
        'owner' => ['manage_members' => true, 'manage_settings' => true, 'manage_billing' => true, 'delete_organization' => true, 'cancel_any_envelope' => true, 'view_all_envelopes' => true, 'manage_folders' => true],
        'admin' => ['manage_members' => true, 'manage_settings' => true, 'manage_billing' => true, 'delete_organization' => false, 'cancel_any_envelope' => true, 'view_all_envelopes' => true, 'manage_folders' => true],
        'member' => ['manage_members' => false, 'manage_settings' => false, 'manage_billing' => false, 'delete_organization' => false, 'cancel_any_envelope' => false, 'view_all_envelopes' => false, 'manage_folders' => false],
    ];

    foreach (MembershipRole::cases() as $role) {
        expect(Permissions::forRole($role))->toBe($phase1[$role->value]);
    }

    expect(Permission::systemGrants(MembershipRole::Owner))->toBe(Permission::cases())
        ->and(array_values(array_diff(Permission::values(), array_map(fn (Permission $p) => $p->value, Permission::systemGrants(MembershipRole::Admin)))))
        ->toBe(['transfer_ownership', 'delete_organization'])
        ->and(Permission::systemGrants(MembershipRole::Member))
        ->toBe([Permission::CreateEnvelopes, Permission::SendEnvelopes, Permission::ViewReports, Permission::ExportData]);
});

test('as policies decidem para owner, admin e member exatamente como na Fase 1', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $admin = attachMember($organization, MembershipRole::Admin);
    $member = attachMember($organization, MembershipRole::Member);
    $otherMember = attachMember($organization, MembershipRole::Member);

    $othersEnvelope = Envelope::factory()->forOrganization($organization, $otherMember)->inProgress()->create();
    $ownEnvelope = Envelope::factory()->forOrganization($organization, $member)->inProgress()->create();
    $folder = folderIn($organization, 'Locação');

    CurrentOrganization::instance()->set($organization);

    $envelopeAbilities = ['view', 'update', 'send', 'cancel', 'delete', 'duplicate', 'move', 'download'];

    foreach ([[$owner, true], [$admin, true], [$member, false]] as [$user, $expected]) {
        foreach ($envelopeAbilities as $ability) {
            expect(Gate::forUser($user)->allows($ability, $othersEnvelope))->toBe($expected, "{$ability} em envelope alheio");
        }
    }

    foreach ($envelopeAbilities as $ability) {
        expect(Gate::forUser($member)->allows($ability, $ownEnvelope))->toBeTrue("{$ability} no próprio envelope");
    }

    foreach ([$owner, $admin, $member] as $user) {
        expect(Gate::forUser($user)->allows('create', Envelope::class))->toBeTrue()
            ->and(Gate::forUser($user)->allows('viewAny', Envelope::class))->toBeTrue()
            ->and(Gate::forUser($user)->allows('view', $folder))->toBeTrue();
    }

    $expectAdmin = [$owner->id => true, $admin->id => true, $member->id => false];

    foreach ([$owner, $admin, $member] as $user) {
        $gate = Gate::forUser($user);
        expect($gate->allows('updateSettings', $organization))->toBe($expectAdmin[$user->id])
            ->and($gate->allows('manageBilling', $organization))->toBe($expectAdmin[$user->id])
            ->and($gate->allows('delete', $organization))->toBe($user->is($owner))
            ->and($gate->allows('create', Folder::class))->toBe($expectAdmin[$user->id])
            ->and($gate->allows('update', $folder))->toBe($expectAdmin[$user->id])
            ->and($gate->allows('viewAny', Membership::class))->toBe($expectAdmin[$user->id])
            ->and($gate->allows('create', MembershipInvitation::class))->toBe($expectAdmin[$user->id]);
    }

    // Admin gere outro admin e members, nunca o owner; ninguém se gere.
    $adminMembership = membershipOf($admin, $organization);
    $memberMembership = membershipOf($member, $organization);
    $ownerMembership = membershipOf($owner, $organization);
    $secondAdmin = membershipOf(attachMember($organization, MembershipRole::Admin), $organization);

    expect(Gate::forUser($admin)->allows('update', $memberMembership))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $secondAdmin))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $ownerMembership))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $adminMembership))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('transferOwnership', $adminMembership))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('transferOwnership', $memberMembership))->toBeFalse()
        ->and(Gate::forUser($member)->allows('update', $secondAdmin))->toBeFalse();

    CurrentOrganization::instance()->clear();
});

test('a abertura de uma organização cria os três papéis de sistema', function () {
    ['organization' => $organization, 'membership' => $membership] = createOrganizationWithOwner();

    $roles = Role::forOrganization($organization)->orderBy('id')->get();

    expect($roles->pluck('key')->all())->toBe(['owner', 'admin', 'member'])
        ->and($roles->pluck('name')->all())->toBe(['Proprietário', 'Administrador', 'Operador'])
        ->and($roles->every(fn (Role $role) => $role->is_system))->toBeTrue()
        // O owner continua identificado pelo enum; role_id só aponta para função personalizada.
        ->and($membership->role_id)->toBeNull();
});

test('a migração de dados cria os papéis de sistema das organizações existentes sem duplicar e sem mudar nada', function () {
    $organization = Organization::factory()->create();
    $trashed = Organization::factory()->create();
    $trashed->delete();
    $membership = Membership::factory()->admin()->create(['organization_id' => $organization->id]);

    expect(Role::forOrganization($organization)->count())->toBe(0);

    $migration = require database_path('migrations/2026_09_11_110103_create_system_roles_for_existing_organizations.php');
    $migration->up();
    $migration->up(); // idempotente

    foreach ([$organization, $trashed] as $org) {
        expect(Role::forOrganization($org->id)->where('is_system', true)->orderBy('key')->pluck('key')->all())
            ->toBe(['admin', 'member', 'owner']);
    }

    $fresh = $membership->fresh();
    expect($fresh->role)->toBe(MembershipRole::Admin)
        ->and($fresh->role_id)->toBeNull()
        ->and($fresh->status)->toBe(MembershipStatus::Active)
        ->and($fresh->grantedPermissions())->toBe(Permission::systemGrants(MembershipRole::Admin));
});

test('organization.permissions mantém as 7 chaves da Fase 1 com a flag desligada e traz o catálogo com ela ligada', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    actingAsMember($member, $organization);
    $permissions = $this->get(route('dashboard'))->viewData('page')['props']['organization']['permissions'];
    expect(array_keys($permissions))->toBe(Permission::LEGACY_SHARED_KEYS);

    enableCustomRoles();

    actingAsMember($member, $organization);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('organization.permissions.create_envelopes', true)
        ->where('organization.permissions.send_envelopes', true)
        ->where('organization.permissions.view_all_envelopes', false)
        ->where('organization.permissions.manage_roles', false)
        ->where('organization.permissions.export_data', true));

    actingAsMember($owner, $organization);
    $permissions = $this->get(route('dashboard'))->viewData('page')['props']['organization']['permissions'];
    expect(array_keys($permissions))->toEqualCanonicalizing(Permission::values())
        ->and(array_unique(array_values($permissions)))->toBe([true]);
});

test('toda rota protegida por org.role tem permissão equivalente, que reproduz o papel exigido na Fase 1', function () {
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $allowed = null;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'org.role:')) {
                $roles = array_map(fn (string $r) => MembershipRole::from(trim($r)), explode(',', substr($middleware, 9)));
                // Grupos aninhados (owner,admin + owner) exigem os dois: interseção.
                $allowed = $allowed === null ? $roles : array_values(array_filter($allowed, fn ($r) => in_array($r, $roles, true)));
            }
        }

        if ($allowed === null) {
            continue;
        }

        $name = (string) $route->getName();
        $required = Permissions::requiredForRoute($name);

        expect($required)->not->toBeNull("Rota {$name} sem permissão mapeada.");

        foreach (MembershipRole::cases() as $role) {
            expect(in_array($required, Permission::systemGrants($role), true))
                ->toBe(in_array($role, $allowed, true), "Rota {$name} para {$role->value}");

            $membership = new Membership(['role' => $role, 'status' => MembershipStatus::Active]);
            expect(Permissions::routeAllows($membership, $allowed, $name))->toBe(in_array($role, $allowed, true));
        }

        $checked++;
    }

    expect($checked)->toBeGreaterThan(10);
});
