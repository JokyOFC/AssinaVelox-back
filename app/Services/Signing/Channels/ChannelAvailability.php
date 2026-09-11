<?php

namespace App\Services\Signing\Channels;

use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Integrations\Contracts\MessagingProvider;
use App\Integrations\Contracts\SmsProvider;
use App\Integrations\Contracts\WhatsAppProvider;
use App\Models\Organization;
use Illuminate\Contracts\Container\Container;

/**
 * Pode esta organização usar este canal AGORA? E, se não, por quê? (Fase 2 §2.9)
 *
 * Um canal SMS/WhatsApp está disponível quando:
 *  1. a flag `sms_whatsapp` está ligada para a organização (global E plano), e
 *  2. o provedor do canal está pronto (`isConfigured()`): o simulador fora de produção, ou
 *     o serviço próprio quando existir — hoje o de produção é sempre desabilitado.
 *
 * O e-mail está sempre disponível. O resultado alimenta o wizard (o remetente não consegue
 * escolher um canal indisponível e lê o motivo) e a validação do sync de destinatários.
 *
 * Os provedores são resolvidos a cada chamada: trocar o binding (config ou teste) vale na hora.
 */
final class ChannelAvailability
{
    public function __construct(private readonly Container $container) {}

    public function provider(DeliveryChannel $channel): ?MessagingProvider
    {
        return match ($channel) {
            DeliveryChannel::Sms => $this->container->make(SmsProvider::class),
            DeliveryChannel::Whatsapp => $this->container->make(WhatsAppProvider::class),
            DeliveryChannel::Email => null,
        };
    }

    /**
     * @return array{channel: string, label: string, available: bool, simulated: bool, provider: string, reason_code: string|null, reason: string|null, notice: string|null}
     */
    public function describe(DeliveryChannel $channel, ?Organization $organization, bool $checkFeature = true): array
    {
        if ($channel === DeliveryChannel::Email) {
            return [
                'channel' => $channel->value,
                'label' => $channel->label(),
                'available' => true,
                'simulated' => false,
                'provider' => 'email',
                'reason_code' => null,
                'reason' => null,
                'notice' => null,
            ];
        }

        $provider = $this->provider($channel);
        $label = $channel->label();

        $base = [
            'channel' => $channel->value,
            'label' => $label,
            'simulated' => $provider?->isSimulated() ?? false,
            'provider' => $provider?->name() ?? 'none',
        ];

        if ($checkFeature && ! ChannelFeatures::smsWhatsapp($organization)) {
            return $base + [
                'available' => false,
                'reason_code' => 'feature_disabled',
                'reason' => 'O envio por SMS e WhatsApp não está habilitado para esta organização.',
                'notice' => null,
            ];
        }

        if ($provider === null || ! $provider->isConfigured()) {
            return $base + [
                'available' => false,
                'reason_code' => 'provider_disabled',
                'reason' => sprintf(
                    'O envio por %s está desativado nesta instalação: o serviço próprio de %s ainda não foi integrado (faltam documentação e credenciais).',
                    $label,
                    $label,
                ),
                'notice' => null,
            ];
        }

        return $base + [
            'available' => true,
            'reason_code' => null,
            'reason' => null,
            'notice' => $provider->isSimulated()
                ? sprintf('Ambiente de testes: as mensagens por %s são simuladas e não chegam ao celular.', $label)
                : null,
        ];
    }

    public function canUse(DeliveryChannel $channel, ?Organization $organization): bool
    {
        return $this->describe($channel, $organization)['available'];
    }

    /**
     * Props do wizard (passo 2) e de `settings.signing`: canais, métodos, PIN e telefone.
     *
     * @return array<string, mixed>
     */
    public function wizardProps(?Organization $organization): array
    {
        $channels = [];

        foreach (DeliveryChannel::cases() as $channel) {
            $channels[$channel->value] = $this->describe($channel, $organization);
        }

        $methods = [];

        foreach (AuthMethod::cases() as $method) {
            $channel = $channels[$method->channel()->value];

            $methods[] = [
                'value' => $method->value,
                'label' => $method->label(),
                'channel' => $method->channel()->value,
                'requires_phone' => $method->requiresPhone(),
                'available' => $channel['available'],
                'simulated' => $channel['simulated'],
                'reason_code' => $channel['reason_code'],
                'reason' => $channel['reason'],
                'notice' => $channel['notice'],
            ];
        }

        return [
            'enabled' => ChannelFeatures::smsWhatsapp($organization),
            'channels' => $channels,
            'auth_methods' => $methods,
            'pin' => [
                'enabled' => ChannelFeatures::pinAuth($organization),
                'min_length' => SenderPins::minLength(),
                'max_length' => SenderPins::maxLength(),
                'notice' => 'Combine o PIN com o participante por fora do AssinaVelox. Ele é pedido depois do código e nunca é enviado pelo sistema.',
            ],
            'phone' => [
                'default_region' => (string) config('assinavelox.channels.default_region', 'BR'),
                'example' => '+55 11 91234-5678',
            ],
        ];
    }
}
