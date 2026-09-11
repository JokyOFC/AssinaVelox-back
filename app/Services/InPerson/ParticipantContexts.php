<?php

namespace App\Services\InPerson;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Signing\EnvelopeExpiration;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerLinkResolver;

/**
 * Contexto de assinatura de um participante SEM o token bruto do convite
 * (docs/fase-2/presencial-e-lote.md §4).
 *
 * O fluxo individual entra pelo token do e-mail (`SignerLinkResolver`). O presencial e o lote
 * entram por outra porta — a sessão presencial aberta pelo anfitrião, o link de lote — mas o
 * participante continua sendo exatamente o mesmo `Recipient`, com o mesmo convite ativo. Este
 * serviço monta o {@see SignerContext} a partir do convite ATIVO do participante e aplica as
 * mesmas regras do resolver, na mesma ordem: coerência envelope/participante/organização,
 * envelope fora do rascunho, revalidação do prazo, prazo próprio do link, estado e vez
 * ({@see SignerLinkResolver::stateFor()}).
 *
 * Com o contexto em mãos, os serviços existentes (Challenges, SenderPins, SignerSessions,
 * RecordAcceptance) rodam sem nenhuma mudança de comportamento.
 *
 * O token do convite não é recuperável (só o digest existe). O contexto leva
 * {@see self::NO_TOKEN}, um marcador que casa com o formato da rota mas não abre convite
 * nenhum: se algum código montar uma URL pública com ele, o link resultante responde o 404
 * genérico — nunca expõe nem reaproveita o convite verdadeiro.
 */
final class ParticipantContexts
{
    public const NO_TOKEN = 'sem-token-de-convite-neste-fluxo';

    public function __construct(private readonly AccessLinks $links) {}

    public function for(Recipient $recipient): ?SignerContext
    {
        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($recipient->getKey())->first();

        if ($recipient === null) {
            return null;
        }

        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($recipient->envelope_id)->first();

        if ($envelope === null || $recipient->organization_id !== $envelope->organization_id) {
            return null;
        }

        if ($envelope->status->isDraftLike()) {
            return null;
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($envelope->organization_id)->first();

        if ($organization === null) {
            return null;
        }

        $envelope = EnvelopeExpiration::revalidate($envelope);
        $recipient = Recipient::withoutOrganizationScope()->whereKey($recipient->getKey())->first() ?? $recipient;

        $link = $this->links->activeFor($recipient);

        if ($link === null
            || $link->organization_id !== $envelope->organization_id
            || $link->envelope_id !== $envelope->getKey()) {
            return null;
        }

        if ($link->isExpired() && $envelope->status === EnvelopeStatus::InProgress) {
            return null;
        }

        $state = SignerLinkResolver::stateFor($envelope, $recipient);

        if ($state === null) {
            return null;
        }

        return new SignerContext(self::NO_TOKEN, $link, $recipient, $envelope, $organization, $state);
    }
}
