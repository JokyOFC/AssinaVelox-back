<?php

namespace App\Services\Organizations;

use App\Enums\MembershipRole;
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
        $query = Recipient::query();

        if (! self::canViewAll($membership)) {
            $query->whereHas('envelope', fn (Builder $envelope) => $envelope
                ->where('envelopes.created_by_user_id', $membership->user_id));
        }

        return $query;
    }

    public static function canSee(Membership $membership, Envelope $envelope): bool
    {
        if ($envelope->organization_id !== $membership->organization_id) {
            return false;
        }

        return self::canViewAll($membership) || $envelope->created_by_user_id === $membership->user_id;
    }
}
