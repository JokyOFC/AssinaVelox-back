<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * Gestão de membros (owner/admin). Invariantes:
 *  - admin não altera/suspende/remove um owner (só o owner gere owners);
 *  - ninguém rebaixa/suspende/remove o ÚLTIMO owner ativo;
 *  - o próprio usuário não se suspende nem se remove por aqui;
 *  - transferência de propriedade: apenas owner, para membership ativa de outro usuário.
 */
class MembershipPolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function manage(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Membership $membership): bool
    {
        if (! $this->isAdmin($user, $membership->organization_id)) {
            return false;
        }

        if ($membership->user_id === $user->getKey()) {
            return false;
        }

        if ($membership->isOwner()) {
            return $this->isOwner($user, $membership->organization_id) && ! $this->isLastActiveOwner($membership);
        }

        return true;
    }

    public function updateStatus(User $user, Membership $membership): bool
    {
        if (! $this->isAdmin($user, $membership->organization_id) || $membership->user_id === $user->getKey()) {
            return false;
        }

        if ($membership->isOwner()) {
            return $this->isOwner($user, $membership->organization_id) && ! $this->isLastActiveOwner($membership);
        }

        return true;
    }

    public function delete(User $user, Membership $membership): bool
    {
        if (! $this->isAdmin($user, $membership->organization_id) || $membership->user_id === $user->getKey()) {
            return false;
        }

        if ($membership->isOwner()) {
            return $this->isOwner($user, $membership->organization_id) && ! $this->isLastActiveOwner($membership);
        }

        return true;
    }

    public function transferOwnership(User $user, Membership $membership): bool
    {
        return $this->isOwner($user, $membership->organization_id)
            && $membership->user_id !== $user->getKey()
            && $membership->isActive();
    }

    public static function isLastActiveOwner(Membership $membership): bool
    {
        if (! $membership->isOwner() || ! $membership->isActive()) {
            return false;
        }

        return ! Membership::query()
            ->where('organization_id', $membership->organization_id)
            ->where('role', MembershipRole::Owner->value)
            ->where('status', MembershipStatus::Active->value)
            ->whereKeyNot($membership->getKey())
            ->exists();
    }
}
