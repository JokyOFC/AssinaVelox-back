<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MembershipInvitation;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * Convites de membros: emitir, reenviar e revogar exigem `manage_members` na organização
 * do convite. A função oferecida no convite passa pela regra anti-escalada
 * (RolePolicy::assign), aplicada no FormRequest.
 */
class MembershipInvitationPolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ManageMembers);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageMembers);
    }

    public function resend(User $user, MembershipInvitation $invitation): bool
    {
        return $this->allows($user, Permission::ManageMembers, $invitation->organization_id);
    }

    public function delete(User $user, MembershipInvitation $invitation): bool
    {
        return $this->allows($user, Permission::ManageMembers, $invitation->organization_id);
    }
}
