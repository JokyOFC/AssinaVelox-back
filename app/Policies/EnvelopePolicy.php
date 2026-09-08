<?php

namespace App\Policies;

use App\Models\Envelope;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * docs/arquitetura.md §7 + RECONCILIACAO Q7: owner/admin veem e gerenciam todos os envelopes
 * da organização corrente; member só os que criou. Regras de status ficam nos serviços
 * (409 "Ação indisponível no status atual"), não na policy.
 */
class EnvelopePolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        return $this->membershipFor($user) !== null;
    }

    public function view(User $user, Envelope $envelope): bool
    {
        $membership = $this->membershipFor($user, $envelope->organization_id);

        if ($membership === null) {
            return false;
        }

        return $membership->role->canManageMembers() || $envelope->created_by_user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $this->membershipFor($user) !== null;
    }

    public function update(User $user, Envelope $envelope): bool
    {
        return $this->creatorOrAdmin($user, $envelope);
    }

    public function send(User $user, Envelope $envelope): bool
    {
        return $this->creatorOrAdmin($user, $envelope);
    }

    public function cancel(User $user, Envelope $envelope): bool
    {
        return $this->creatorOrAdmin($user, $envelope);
    }

    public function delete(User $user, Envelope $envelope): bool
    {
        return $this->creatorOrAdmin($user, $envelope);
    }

    public function duplicate(User $user, Envelope $envelope): bool
    {
        return $this->view($user, $envelope);
    }

    public function move(User $user, Envelope $envelope): bool
    {
        return $this->creatorOrAdmin($user, $envelope);
    }

    public function download(User $user, Envelope $envelope): bool
    {
        return $this->view($user, $envelope);
    }

    protected function creatorOrAdmin(User $user, Envelope $envelope): bool
    {
        $membership = $this->membershipFor($user, $envelope->organization_id);

        if ($membership === null) {
            return false;
        }

        return $membership->role->canManageMembers() || $envelope->created_by_user_id === $user->getKey();
    }
}
