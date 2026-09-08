<?php

namespace App\Policies;

use App\Models\MembershipInvitation;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * Convites de membros: emitir, reenviar e revogar exigem owner/admin na organização do convite.
 */
class MembershipInvitationPolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function resend(User $user, MembershipInvitation $invitation): bool
    {
        return $this->isAdmin($user, $invitation->organization_id);
    }

    public function delete(User $user, MembershipInvitation $invitation): bool
    {
        return $this->isAdmin($user, $invitation->organization_id);
    }
}
