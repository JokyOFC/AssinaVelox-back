<?php

namespace App\Services\Organizations;

use App\Enums\MembershipRole;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Recipient;
use Illuminate\Database\Eloquent\Builder;

/**
 * Visibilidade de envelopes por papel (RECONCILIACAO Q7): owner/admin veem todos os
 * envelopes da organização corrente; member vê apenas os que criou. As consultas já
 * carregam o escopo global da organização (BelongsToOrganization).
 */
class EnvelopeVisibility
{
    public static function canViewAll(Membership $membership): bool
    {
        return $membership->role->isAtLeast(MembershipRole::Admin);
    }

    /**
     * @return Builder<Envelope>
     */
    public static function envelopes(Membership $membership): Builder
    {
        $query = Envelope::query();

        if (! self::canViewAll($membership)) {
            $query->where('envelopes.created_by_user_id', $membership->user_id);
        }

        return $query;
    }

    /**
     * Recipients dos envelopes visíveis ao usuário.
     *
     * @return Builder<Recipient>
     */
    public static function recipients(Membership $membership): Builder
    {
        $canViewAll = self::canViewAll($membership);

        // `whereHas('envelope')` aplica o SoftDeletingScope do Envelope: signatários de
        // documentos excluídos ficam fora para TODOS os papéis (owner/admin inclusive).
        return Recipient::query()->whereHas(
            'envelope',
            function (Builder $envelope) use ($canViewAll, $membership): void {
                if (! $canViewAll) {
                    $envelope->where('envelopes.created_by_user_id', $membership->user_id);
                }
            },
        );
    }

    /**
     * Bytes armazenados nas versões dos documentos VISÍVEIS ao usuário (RECONCILIACAO Q7:
     * "contagens respeitam o escopo"). `whereHas` também exclui envelopes soft-deleted.
     */
    public static function storageUsedBytes(Membership $membership): int
    {
        $canViewAll = self::canViewAll($membership);

        return (int) DocumentVersion::query()
            ->whereHas('document.envelope', function (Builder $envelope) use ($canViewAll, $membership): void {
                if (! $canViewAll) {
                    $envelope->where('envelopes.created_by_user_id', $membership->user_id);
                }
            })
            ->sum('size_bytes');
    }

    public static function canSee(Membership $membership, Envelope $envelope): bool
    {
        if ($envelope->organization_id !== $membership->organization_id) {
            return false;
        }

        return self::canViewAll($membership) || $envelope->created_by_user_id === $membership->user_id;
    }
}
