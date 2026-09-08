<?php

namespace App\Services\Accounts;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\User;
use App\Policies\MembershipPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Exclusão da conta do usuário (ROUTES §1.2 `profile.destroy`).
 *
 * Duas invariantes bloqueiam a exclusão — nenhuma delas pode virar erro interno:
 *
 * 1. arquitetura.md §3.1: "Pelo menos um `owner` por organização (invariante de serviço)".
 *    `memberships.user_id` é CASCADE, então apagar o único proprietário ativo deixaria a
 *    organização órfã. O usuário precisa transferir a propriedade ou excluir a organização.
 * 2. docs/banco-de-dados.md §4.2: `users` → `envelopes.created_by_user_id` é **RESTRICT**
 *    ("Autor é parte da evidência"). Mantemos o RESTRICT (nada de `nullOnDelete`): quem
 *    criou documentos não pode sumir da trilha, e a exclusão é recusada com mensagem clara
 *    em vez de estourar violação de chave estrangeira.
 */
class AccountDeletion
{
    /**
     * Motivos (PT-BR) que impedem a exclusão. Vazio = a conta pode ser excluída.
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        $organizations = Membership::query()
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('role', MembershipRole::Owner->value)
            ->where('status', MembershipStatus::Active->value)
            ->get()
            ->filter(fn (Membership $membership): bool => MembershipPolicy::isLastActiveOwner($membership))
            ->map(fn (Membership $membership): string => $membership->organization->name)
            ->values();

        if ($organizations->isNotEmpty()) {
            $blockers[] = $organizations->count() === 1
                ? 'Você é o único proprietário de '.$organizations->first().'. Transfira a propriedade para outro usuário ou exclua a organização antes de excluir sua conta.'
                : 'Você é o único proprietário de: '.$organizations->implode(', ').'. Transfira a propriedade para outro usuário ou exclua essas organizações antes de excluir sua conta.';
        }

        if ($this->createdEnvelopesCount($user) > 0) {
            $blockers[] = 'Sua conta consta como autora de documentos enviados e faz parte da trilha de evidências deles. Peça a exclusão da organização para remover esses documentos antes de excluir sua conta.';
        }

        return $blockers;
    }

    /**
     * Exclui a conta. Só deve ser chamado quando blockers() estiver vazio.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill(['current_organization_id' => null])->save();
            $user->memberships()->delete();
            $user->delete();
        });
    }

    /**
     * Conta os envelopes criados pelo usuário em qualquer organização, inclusive os
     * soft-deleted (a FK RESTRICT não distingue soft delete).
     */
    protected function createdEnvelopesCount(User $user): int
    {
        return Envelope::withoutOrganizationScope()
            ->withTrashed()
            ->where('created_by_user_id', $user->getKey())
            ->count();
    }
}
