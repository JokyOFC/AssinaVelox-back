<?php

namespace App\Services\Signing\Channels;

use App\Enums\AuditEventType;
use App\Enums\DeliveryChannel;
use App\Models\AuditEvent;
use App\Models\AuthChallenge;
use App\Models\DeliveryAttempt;
use App\Models\Recipient;

/**
 * O código que o participante confirmou saiu por um SIMULADOR? (revisão da onda B)
 *
 * Com o simulador de SMS/WhatsApp nada é transmitido: a linha de `delivery_attempts` fica com
 * `meta.simulated = true`. A evidência (dossiê do remetente e página de evidências do PDF) não
 * pode afirmar "Código por SMS" como se um celular tivesse recebido o código — ela diz que o
 * envio foi simulado.
 *
 * Caminho: o último `challenge.verified` do participante → o desafio (`challenge_ulid`) → a
 * tentativa de entrega vinculada a ele. E-mail nunca é marcado aqui.
 */
final class SimulatedChannelEvidence
{
    public const NOTE = 'Envio simulado nesta instalação: nenhuma mensagem foi transmitida ao celular.';

    public const LABEL_SUFFIX = ' (envio simulado — nenhuma mensagem transmitida)';

    public static function wasSimulated(Recipient $recipient): bool
    {
        /** @var AuditEvent|null $event */
        $event = AuditEvent::query()->withoutGlobalScopes()
            ->where('organization_id', $recipient->organization_id)
            ->where('recipient_id', $recipient->getKey())
            ->where('event_type', AuditEventType::ChallengeVerified->value)
            ->orderByDesc('id')
            ->first();

        $ulid = $event?->payload['challenge_ulid'] ?? null;

        if (! is_string($ulid) || $ulid === '') {
            return false;
        }

        /** @var AuthChallenge|null $challenge */
        $challenge = AuthChallenge::withoutOrganizationScope()
            ->where('ulid', $ulid)
            ->where('recipient_id', $recipient->getKey())
            ->first();

        if ($challenge === null || $challenge->channel === DeliveryChannel::Email || $challenge->delivery_attempt_id === null) {
            return false;
        }

        /** @var DeliveryAttempt|null $attempt */
        $attempt = DeliveryAttempt::withoutOrganizationScope()->whereKey($challenge->delivery_attempt_id)->first();

        return (bool) ($attempt?->meta['simulated'] ?? false);
    }
}
