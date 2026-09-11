<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Team;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;
use App\Support\Permissions;

/**
 * Times (descoberta automática: App\Models\Team → TeamPolicy).
 *
 *  - ver: `manage_teams` ou `manage_members`;
 *  - criar/editar/excluir e definir participantes: `manage_teams`;
 *  - pastas do time: `manage_folders`.
 *
 * A regra anti-escalada (ninguém entra num time que dá acesso a pasta que não tem, nem
 * concede ao próprio time acesso que não tem) fica nos FormRequests, com
 * PermissionsFolderAccess::actorCovers.
 */
class TeamPolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        $membership = $this->membershipFor($user);

        return $membership !== null && Permissions::hasAny($membership, Permission::ManageTeams, Permission::ManageMembers);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageTeams);
    }

    public function update(User $user, Team $team): bool
    {
        return $this->allows($user, Permission::ManageTeams, $team->organization_id);
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->update($user, $team);
    }

    public function manageFolders(User $user, Team $team): bool
    {
        return $this->allows($user, Permission::ManageFolders, $team->organization_id);
    }
}
