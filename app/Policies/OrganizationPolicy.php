<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * Organização: configurações e cobrança para owner/admin; exclusão só para owner.
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
        return $this->isAdmin($user, $organization);
    }

    public function manageBilling(User $user, Organization $organization): bool
    {
        return $this->isAdmin($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->isOwner($user, $organization);
    }

    public function switchTo(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization) !== null;
    }
}
