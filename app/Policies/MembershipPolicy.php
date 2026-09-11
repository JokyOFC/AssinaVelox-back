<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\Membership;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;
use App\Support\Permissions;

/**
 * Gestão de membros (`manage_members`). Invariantes:
 *  - só um proprietário altera/suspende/remove outro proprietário (owner é especial);
 *  - ninguém rebaixa/suspende/remove o ÚLTIMO owner ativo;
 *  - o próprio usuário não se altera, suspende nem remove por aqui;
 *  - anti-escalada: só se gere quem não tem poderes além dos seus (função do alvo ⊆ ator)
 *    — nos papéis de sistema isso é exatamente "admin não toca em owner";
 *  - transferência de propriedade: `transfer_ownership` (só owner), para membership ativa
 *    de outro usuário.
 */
class MembershipPolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ManageMembers);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, Permission::ManageMembers);
    }

    public function update(User $user, Membership $membership): bool
    {
        return $this->canManageTarget($user, $membership);
    }

    public function updateStatus(User $user, Membership $membership): bool
    {
        return $this->canManageTarget($user, $membership);
    }

    public function delete(User $user, Membership $membership): bool
    {
        return $this->canManageTarget($user, $membership);
    }

    /**
     * Definir as pastas a que a pessoa tem acesso direto.
     */
    public function manageFolders(User $user, Membership $membership): bool
    {
        $actor = $this->membershipFor($user, $membership->organization_id);

        return $actor !== null
            && $actor->hasPermission(Permission::ManageFolders)
            && $membership->user_id !== $user->getKey()
            && ! $membership->isOwner();
    }

    public function transferOwnership(User $user, Membership $membership): bool
    {
        return $this->allows($user, Permission::TransferOwnership, $membership->organization_id)
            && $membership->user_id !== $user->getKey()
            && $membership->isActive();
    }

    protected function canManageTarget(User $user, Membership $membership): bool
    {
        $actor = $this->membershipFor($user, $membership->organization_id);

        if ($actor === null || ! $actor->hasPermission(Permission::ManageMembers)) {
            return false;
        }

        if ($membership->user_id === $user->getKey()) {
            return false;
        }

        if ($membership->isOwner()) {
            return $actor->isOwner() && ! self::isLastActiveOwner($membership);
        }

        return Permissions::coversMembership($actor, $membership);
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
