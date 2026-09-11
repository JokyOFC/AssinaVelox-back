<?php

namespace App\Policies\Concerns;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Membership ATIVA do usuário na organização alvo. Usa a membership já resolvida pelo
 * middleware `org` quando a organização coincide; caso contrário consulta o banco.
 *
 * Fase 2: as policies decidem por PERMISSÃO (`allows`), nunca pelo enum de papel. Os
 * papéis de sistema reproduzem as permissões da Fase 1 (App\Enums\Permission::systemGrants).
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

    /**
     * O usuário tem a permissão na organização alvo (membership ativa)?
     */
    protected function allows(User $user, Permission $permission, Organization|int|null $organization = null): bool
    {
        return $this->membershipFor($user, $organization)?->hasPermission($permission) ?? false;
    }

    protected function isAtLeast(User $user, MembershipRole $role, Organization|int|null $organization = null): bool
    {
        $membership = $this->membershipFor($user, $organization);

        return $membership !== null && $membership->role->isAtLeast($role);
    }

    /**
     * @deprecated Fase 2: decida por permissão com `allows()`. Mantido só para código que
     *             ainda compare papéis de sistema (owner/admin).
     */
    protected function isAdmin(User $user, Organization|int|null $organization = null): bool
    {
        return $this->isAtLeast($user, MembershipRole::Admin, $organization);
    }

    protected function isOwner(User $user, Organization|int|null $organization = null): bool
    {
        return $this->membershipFor($user, $organization)?->isOwner() ?? false;
    }
}
