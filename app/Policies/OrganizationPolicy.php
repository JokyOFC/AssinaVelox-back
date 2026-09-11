<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * Organização: configurações (`manage_settings`), cobrança (`manage_billing`) e exclusão
 * (`delete_organization`, exclusiva do proprietário).
 */
class OrganizationPolicy
{
    use ResolvesMembership;

    public function view(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization) !== null;
    }

    public function updateSettings(User $user, Organization $organization): bool
    {
        return $this->allows($user, Permission::ManageSettings, $organization);
    }

    public function manageBilling(User $user, Organization $organization): bool
    {
        return $this->allows($user, Permission::ManageBilling, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->allows($user, Permission::DeleteOrganization, $organization);
    }

    public function switchTo(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization) !== null;
    }

    /**
     * Ponto genérico para as demais áreas: `$user->can('permission', [$organization, Permission::X])`.
     */
    public function permission(User $user, Organization $organization, Permission $permission): bool
    {
        return $this->allows($user, $permission, $organization);
    }
}
