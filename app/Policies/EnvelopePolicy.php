<?php

namespace App\Policies;

use App\Enums\FolderAccessLevel;
use App\Enums\Permission;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;
use App\Services\Organizations\EnvelopeVisibility;

/**
 * Envelopes (arquitetura §7 + RECONCILIACAO Q7 + Fase 2 §2.14), decididos por permissão:
 *
 *  - ver/baixar: EnvelopeVisibility::canSee (ver todos, criador ou acesso à pasta);
 *  - criar/duplicar: `create_envelopes`;
 *  - editar/mover/excluir: ver E (`manage_any_envelope` OU criador com `create_envelopes`
 *    OU acesso "gerenciar" à pasta);
 *  - enviar: ver E `send_envelopes` E (criador OU `manage_any_envelope` OU pasta "gerenciar");
 *  - cancelar: ver E (`cancel_any_envelope` OU `send_envelopes` como criador/pasta "gerenciar").
 *
 * Papéis de sistema: owner/admin têm tudo; member (Operador) cria, envia e cancela os
 * próprios — exatamente a Fase 1. Regras de STATUS continuam nos serviços (409).
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

        return $membership !== null && EnvelopeVisibility::canSee($membership, $envelope);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CreateEnvelopes);
    }

    public function update(User $user, Envelope $envelope): bool
    {
        return $this->canEdit($user, $envelope);
    }

    public function send(User $user, Envelope $envelope): bool
    {
        $membership = $this->visibleMembership($user, $envelope);

        if ($membership === null || ! $membership->hasPermission(Permission::SendEnvelopes)) {
            return false;
        }

        return $this->isCreator($user, $envelope)
            || $membership->hasPermission(Permission::ManageAnyEnvelope)
            || $this->managesFolder($membership, $envelope);
    }

    public function cancel(User $user, Envelope $envelope): bool
    {
        $membership = $this->visibleMembership($user, $envelope);

        if ($membership === null) {
            return false;
        }

        if ($membership->hasPermission(Permission::CancelAnyEnvelope)) {
            return true;
        }

        return $membership->hasPermission(Permission::SendEnvelopes)
            && ($this->isCreator($user, $envelope) || $this->managesFolder($membership, $envelope));
    }

    public function delete(User $user, Envelope $envelope): bool
    {
        return $this->canEdit($user, $envelope);
    }

    public function duplicate(User $user, Envelope $envelope): bool
    {
        $membership = $this->visibleMembership($user, $envelope);

        return $membership !== null && $membership->hasPermission(Permission::CreateEnvelopes);
    }

    public function move(User $user, Envelope $envelope): bool
    {
        return $this->canEdit($user, $envelope);
    }

    public function download(User $user, Envelope $envelope): bool
    {
        return $this->view($user, $envelope);
    }

    protected function canEdit(User $user, Envelope $envelope): bool
    {
        $membership = $this->visibleMembership($user, $envelope);

        if ($membership === null) {
            return false;
        }

        return $membership->hasPermission(Permission::ManageAnyEnvelope)
            || ($this->isCreator($user, $envelope) && $membership->hasPermission(Permission::CreateEnvelopes))
            || $this->managesFolder($membership, $envelope);
    }

    /**
     * Membership ativa que ENXERGA o envelope (nenhuma ação sobre o que não se vê).
     */
    protected function visibleMembership(User $user, Envelope $envelope): ?Membership
    {
        $membership = $this->membershipFor($user, $envelope->organization_id);

        return $membership !== null && EnvelopeVisibility::canSee($membership, $envelope) ? $membership : null;
    }

    protected function isCreator(User $user, Envelope $envelope): bool
    {
        return $envelope->created_by_user_id === $user->getKey();
    }

    protected function managesFolder(Membership $membership, Envelope $envelope): bool
    {
        return EnvelopeVisibility::folderLevel($membership, $envelope) === FolderAccessLevel::Manage;
    }
}
