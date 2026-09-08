<?php

namespace App\Policies\Concerns;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Membership ATIVA do usuário na organização alvo. Usa a membership já resolvida pelo
 * middleware `org` quando a organização coincide; caso contrário consulta o banco.
 */
trait ResolvesMembership
{
    protected function membershipFor(User $user, Organization|int|null $organization = null): ?Membership
    {
        $current = CurrentOrganization::instance();
        $organizationId = $organization instanceof Organization
            ? $organization->getKey()
            : ($organization ?? $current->id());

        if ($organizationId === null) {
            return null;
        }

        $resolved = $current->membership();

        if ($resolved !== null
            && $resolved->user_id === $user->getKey()
            && $resolved->organization_id === $organizationId) {
            return $resolved->isActive() ? $resolved : null;
        }

        $membership = $user->membershipFor($organizationId);

        return $membership?->status === MembershipStatus::Active ? $membership : null;
    }

    protected function isAtLeast(User $user, MembershipRole $role, Organization|int|null $organization = null): bool
    {
        $membership = $this->membershipFor($user, $organization);

        return $membership !== null && $membership->role->isAtLeast($role);
    }

    protected function isAdmin(User $user, Organization|int|null $organization = null): bool
    {
        return $this->isAtLeast($user, MembershipRole::Admin, $organization);
    }

    protected function isOwner(User $user, Organization|int|null $organization = null): bool
    {
        return $this->isAtLeast($user, MembershipRole::Owner, $organization);
    }
}
