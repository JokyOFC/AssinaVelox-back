<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de permissões, funções, times e acesso por pasta (B-PERM)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes.
*/

use App\Enums\FolderAccessLevel;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\Invitations;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';

if (! function_exists('enableCustomRoles')) {
    function enableCustomRoles(bool $enabled = true): void
    {
        config(['assinavelox.features.custom_roles' => $enabled]);
    }
}

if (! function_exists('createCustomRole')) {
    /**
     * @param  list<Permission>  $permissions
     */
    function createCustomRole(Organization $organization, string $name, array $permissions): Role
    {
        $role = new Role;
        $role->forceFill([
            'organization_id' => $organization->id,
            'name' => $name,
            'is_system' => false,
        ])->save();

        $role->syncPermissions($permissions);

        return $role->fresh();
    }
}

if (! function_exists('attachWithCustomRole')) {
    function attachWithCustomRole(Organization $organization, Role $role, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Member,
            'role_id' => $role->id,
            'status' => MembershipStatus::Active,
        ]);

        return $user;
    }
}

if (! function_exists('membershipOf')) {
    function membershipOf(User $user, Organization $organization): Membership
    {
        return Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }
}

if (! function_exists('grantFolder')) {
    /**
     * @param  'role'|'team'|'membership'  $subject
     */
    function grantFolder(Folder $folder, string $subject, int $subjectId, FolderAccessLevel $level = FolderAccessLevel::View): FolderPermission
    {
        $grant = new FolderPermission;
        $grant->forceFill([
            'organization_id' => $folder->organization_id,
            'folder_id' => $folder->id,
            $subject.'_id' => $subjectId,
            'level' => $level,
        ])->save();

        return $grant;
    }
}

if (! function_exists('folderIn')) {
    function folderIn(Organization $organization, string $name): Folder
    {
        return Folder::factory()->create(['organization_id' => $organization->id, 'name' => $name]);
    }
}

if (! function_exists('pendingInvitationWithToken')) {
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: MembershipInvitation, 1: string}
     */
    function pendingInvitationWithToken(array $attributes): array
    {
        $token = Invitations::generateToken();

        $invitation = MembershipInvitation::factory()->create([
            ...$attributes,
            'token_digest' => Invitations::digest($token),
        ]);

        return [$invitation, $token];
    }
}
