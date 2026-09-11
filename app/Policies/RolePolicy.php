<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;
use App\Support\Permissions;

/**
 * Funções da organização (descoberta automática: App\Models\Role → RolePolicy).
 *
 *  - ver a lista: `manage_roles` ou `manage_members` (para atribuir);
 *  - criar/editar/excluir função personalizada: `manage_roles`; papéis de sistema nunca;
 *  - anti-escalada: só edita/exclui uma função cujas permissões o ator já tem, e só
 *    concede permissões que tem (conferido no FormRequest com Permissions::covers);
 *  - atribuir a alguém: `manage_members` + ter todas as permissões da função; nunca owner
 *    (propriedade só por transferência).
 */
class RolePolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        $membership = $this->membershipFor($user);

        return $membership !== null && Permissions::hasAny($membership, Permission::ManageRoles, Permission::ManageMembers);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageRoles);
    }

    public function update(User $user, Role $role): bool
    {
        $actor = $this->actorFor($user, $role);

        return $actor !== null
            && ! $role->is_system
            && $actor->hasPermission(Permission::ManageRoles)
            && Permissions::covers($actor, $role->grantedPermissions());
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    /**
     * Pastas liberadas para quem tem a função (papel de sistema também, exceto owner).
     */
    public function manageFolders(User $user, Role $role): bool
    {
        $actor = $this->actorFor($user, $role);

        return $actor !== null
            && $actor->hasPermission(Permission::ManageFolders)
            && $role->systemRole() !== MembershipRole::Owner;
    }

    public function assign(User $user, Role $role): bool
    {
        $actor = $this->actorFor($user, $role);

        return $actor !== null
            && $actor->hasPermission(Permission::ManageMembers)
            && $role->systemRole() !== MembershipRole::Owner
            && Permissions::covers($actor, $role->grantedPermissions());
    }

    protected function actorFor(User $user, Role $role): ?Membership
    {
        return $this->membershipFor($user, $role->organization_id);
    }
}
