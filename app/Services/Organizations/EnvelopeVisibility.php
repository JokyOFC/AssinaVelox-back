<?php

namespace App\Services\Organizations;

use App\Enums\FolderAccessLevel;
use App\Enums\Permission;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Recipient;
use App\Support\PermissionsFolderAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * PONTO ÚNICO da visibilidade de envelopes (RECONCILIACAO Q7 + Fase 2 §2.14):
 *
 *  - quem tem `view_all_envelopes` (owner/admin e funções que a concedem) vê todos os
 *    envelopes da organização corrente;
 *  - os demais veem os que criaram E os das pastas a que têm acesso — direto, pela
 *    função ou por um time (App\Support\PermissionsFolderAccess).
 *
 * Listagens, busca, contagens (sidebar/dashboard), exportações CSV, lembretes em lote e o
 * resumo diário passam por aqui; a policy do envelope (`view`) usa `canSee`. As consultas
 * já carregam o escopo global da organização (BelongsToOrganization).
 */
class EnvelopeVisibility
{
    public static function canViewAll(Membership $membership): bool
    {
        return $membership->hasPermission(Permission::ViewAllEnvelopes);
    }

    /**
     * Aplica a regra a uma consulta sobre a tabela `envelopes` (também dentro de whereHas).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query, Membership $membership): Builder
    {
        if (self::canViewAll($membership)) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($membership): void {
            $visible->where('envelopes.created_by_user_id', $membership->user_id);

            if ($membership->isActive()) {
                $visible->orWhereIn('envelopes.folder_id', PermissionsFolderAccess::folderIdsQuery($membership));
            }
        });
    }

    /**
     * @return Builder<Envelope>
     */
    public static function envelopes(Membership $membership): Builder
    {
        return self::constrain(Envelope::query(), $membership);
    }

    /**
     * Recipients dos envelopes visíveis ao usuário.
     *
     * @return Builder<Recipient>
     */
    public static function recipients(Membership $membership): Builder
    {
        // `whereHas('envelope')` aplica o SoftDeletingScope do Envelope: signatários de
        // documentos excluídos ficam fora para TODOS os papéis (owner/admin inclusive).
        return Recipient::query()->whereHas(
            'envelope',
            fn (Builder $envelope) => self::constrain($envelope, $membership),
        );
    }

    /**
     * Bytes armazenados nas versões dos documentos VISÍVEIS ao usuário (RECONCILIACAO Q7:
     * "contagens respeitam o escopo"). `whereHas` também exclui envelopes soft-deleted.
     */
    public static function storageUsedBytes(Membership $membership): int
    {
        return (int) DocumentVersion::query()
            ->whereHas('document.envelope', fn (Builder $envelope) => self::constrain($envelope, $membership))
            ->sum('size_bytes');
    }

    public static function canSee(Membership $membership, Envelope $envelope): bool
    {
        if ($envelope->organization_id !== $membership->organization_id) {
            return false;
        }

        return self::canViewAll($membership)
            || $envelope->created_by_user_id === $membership->user_id
            || self::folderLevel($membership, $envelope) !== null;
    }

    /**
     * Nível de acesso da membership à pasta do envelope (null: sem pasta ou sem acesso).
     */
    public static function folderLevel(Membership $membership, Envelope $envelope): ?FolderAccessLevel
    {
        if ($envelope->organization_id !== $membership->organization_id) {
            return null;
        }

        return PermissionsFolderAccess::levelFor($membership, $envelope->folder_id);
    }
}
