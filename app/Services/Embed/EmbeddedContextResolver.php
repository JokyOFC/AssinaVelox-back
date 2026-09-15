<?php

namespace App\Services\Embed;

use App\Enums\AccessLinkPurpose;
use App\Enums\EnvelopeStatus;
use App\Models\EmbeddedSigningSession;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Services\Signing\EnvelopeExpiration;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerLinkResolver;

/**
 * Contexto do participante para o widget embutido (docs/fase-3/widget-embutido.md §3).
 *
 * É a mesma decisão de App\Services\Signing\SignerLinkResolver — link revogado, envelope em
 * rascunho, prazo revalidado, vez no sequencial, estado da pessoa —, só que a partir do LINK de
 * convite gravado na sessão embutida, e não do token bruto (que o widget nunca vê). Por isso toda
 * revogação do convite (troca de e-mail, reenvio, recusa, cancelamento) derruba também a sessão
 * embutida, sem código extra.
 *
 * O `token` do SignerContext é um marcador fixo: os serviços do fluxo público só o usam para
 * montar URLs `/assinar/{token}/…`, e o widget remove toda URL desse tipo das props
 * ({@see EmbedScreenProps}). O token do convite não chega ao widget.
 */
final class EmbeddedContextResolver
{
    public const PLACEHOLDER_TOKEN = 'embedded-session-without-invitation-token';

    public function forSession(EmbeddedSigningSession $session): ?SignerContext
    {
        /** @var RecipientAccessLink|null $link */
        $link = RecipientAccessLink::withoutOrganizationScope()
            ->whereKey($session->access_link_id)
            ->where('purpose', AccessLinkPurpose::Signing->value)
            ->first();

        if ($link === null
            || $link->recipient_id !== $session->recipient_id
            || $link->envelope_id !== $session->envelope_id
            || $link->organization_id !== $session->organization_id) {
            return null;
        }

        return $this->fromLink($link);
    }

    public function fromLink(RecipientAccessLink $link): ?SignerContext
    {
        if ($link->isRevoked()) {
            return null;
        }

        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($link->recipient_id)->first();
        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($link->envelope_id)->first();

        if ($recipient === null || $envelope === null) {
            return null;
        }

        if ($recipient->envelope_id !== $envelope->getKey()
            || $recipient->organization_id !== $envelope->organization_id
            || $link->organization_id !== $envelope->organization_id) {
            return null;
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($envelope->organization_id)->first();

        if ($organization === null || $envelope->status->isDraftLike()) {
            return null;
        }

        $envelope = EnvelopeExpiration::revalidate($envelope);

        if ($link->isExpired() && $envelope->status === EnvelopeStatus::InProgress) {
            return null;
        }

        $recipient = Recipient::withoutOrganizationScope()->whereKey($recipient->getKey())->first() ?? $recipient;

        $state = SignerLinkResolver::stateFor($envelope, $recipient);

        if ($state === null) {
            return null;
        }

        return new SignerContext(self::PLACEHOLDER_TOKEN, $link, $recipient, $envelope, $organization, $state);
    }
}
