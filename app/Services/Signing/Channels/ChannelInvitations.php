<?php

namespace App\Services\Signing\Channels;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Models\DeliveryAttempt;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Rules\PhoneE164;
use App\Services\Signing\Exceptions\SigningRejectedException;
use Illuminate\Support\Facades\Log;

/**
 * Entrega do convite pelo canal do participante (`recipients.delivery_channel`).
 *
 * O e-mail de convite continua saindo sempre, como na Fase 1 (é o registro). Quando o
 * participante tem `delivery_channel = sms|whatsapp`, sai TAMBÉM um aviso curto com o link
 * pelo canal. Sem canal (nulo, o padrão), nada acontece — com a flag desligada o
 * comportamento é exatamente o da Fase 1.
 *
 * Canal indisponível na hora do envio (provedor desabilitado, telefone inválido, limite diário
 * da organização) não impede o convite por e-mail: o aviso é pulado e isso vai para o log.
 */
final class ChannelInvitations
{
    public function __construct(
        private readonly ChannelAvailability $availability,
        private readonly ChannelDelivery $delivery,
    ) {}

    /**
     * Chamado por InvitationDispatcher depois do e-mail. Enfileira o aviso quando há canal.
     */
    public function afterInvitation(Recipient $recipient, string $url, bool $isReminder, string $correlationId): bool
    {
        if (self::channelOf($recipient) === null) {
            return false;
        }

        SendChannelInvitation::dispatch($recipient->getKey(), $url, $isReminder, $correlationId);

        return true;
    }

    public function deliver(Recipient $recipient, Envelope $envelope, string $url, bool $isReminder, string $correlationId): ?DeliveryAttempt
    {
        $channel = self::channelOf($recipient);

        if ($channel === null) {
            return null;
        }

        $phone = PhoneE164::normalize((string) $recipient->phone);

        if ($phone === null) {
            $this->skip($recipient, $channel, 'invalid_phone', $correlationId);

            return null;
        }

        $provider = $this->availability->provider($channel);

        if ($provider === null || ! $provider->isConfigured()) {
            $this->skip($recipient, $channel, 'provider_disabled', $correlationId);

            return null;
        }

        try {
            $this->delivery->assertWithinOrganizationLimit((int) $recipient->organization_id);
        } catch (SigningRejectedException) {
            $this->skip($recipient, $channel, 'organization_daily_limit', $correlationId);

            return null;
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($envelope->organization_id)->first();
        $organizationName = $organization->name ?? (string) config('app.name');
        $purpose = $isReminder ? DeliveryPurpose::Resend : DeliveryPurpose::Invitation;

        return $this->delivery->send(
            recipient: $recipient,
            channel: $channel,
            toE164: $phone,
            purpose: $purpose,
            template: ChannelMessages::template($channel, $purpose),
            parameters: [
                'recipient_name' => ChannelMessages::firstName($recipient->name),
                'organization' => ChannelMessages::plain($organizationName, 40),
                'title' => ChannelMessages::plain($envelope->title, 40),
                'url' => $url,
            ],
            text: ChannelMessages::invitationText($organizationName, $envelope->title, $url, $isReminder),
            sensitive: ['url'],
            correlationId: $correlationId,
            meta: ['envelope' => $envelope->display_code],
        );
    }

    public static function channelOf(Recipient $recipient): ?DeliveryChannel
    {
        $raw = $recipient->getAttribute('delivery_channel');
        $channel = is_string($raw) ? DeliveryChannel::tryFrom($raw) : null;

        return $channel === null || $channel === DeliveryChannel::Email ? null : $channel;
    }

    private function skip(Recipient $recipient, DeliveryChannel $channel, string $reason, string $correlationId): void
    {
        Log::notice('channels.invitation.skipped', [
            'recipient' => $recipient->ulid,
            'channel' => $channel->value,
            'reason' => $reason,
            'correlation_id' => $correlationId,
        ]);
    }
}
