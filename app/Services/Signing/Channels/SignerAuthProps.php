<?php

namespace App\Services\Signing\Channels;

use App\Enums\DeliveryChannel;
use App\Rules\PhoneE164;
use App\Services\Signing\SignerContext;
use Illuminate\Http\Request;

/**
 * Props de autenticação da página pública (`sign/show`, tela `identify`).
 *
 * Contrato para `SignerPageProps::build()` (fora da área C-CAN): acrescentar
 * `'auth' => app(SignerAuthProps::class)->for($context, $request)` e trocar
 * `'auth_methods' => [AuthMethod::EmailOtp->value]` por
 * `SignerAuthProps::authMethods($context)`. Ver docs/fase-2/canais-e-pin.md §9.
 */
final class SignerAuthProps
{
    public function __construct(
        private readonly ChannelAvailability $availability,
        private readonly SenderPins $pins,
    ) {}

    /**
     * @return array{method: string, method_label: string, channel: string, channel_label: string, destination: string, simulated: bool, available: bool, unavailable_reason: string|null, notice: string|null, step: 'code'|'pin', pin: array<string, mixed>|null}
     */
    public function for(SignerContext $context, Request $request): array
    {
        $method = $context->recipient->auth_method;
        $channel = $method->channel();

        // A flag não é conferida aqui: um participante que já tem SMS continua atendido se a
        // flag for desligada depois. Só o provedor decide se o código pode sair.
        $describe = $channel === DeliveryChannel::Email
            ? null
            : $this->availability->describe($channel, $context->organization, checkFeature: false);

        $pin = $this->pins->props($context, $request);

        return [
            'method' => $method->value,
            'method_label' => $method->label(),
            'channel' => $channel->value,
            'channel_label' => $channel->label(),
            'destination' => $channel === DeliveryChannel::Email
                ? $context->recipient->masked_email
                : PhoneE164::mask($context->recipient->phone),
            'simulated' => $describe['simulated'] ?? false,
            'available' => $describe['available'] ?? true,
            'unavailable_reason' => $describe['reason'] ?? null,
            'notice' => $describe['notice'] ?? null,
            'step' => $pin !== null && $pin['step_active'] ? 'pin' : 'code',
            'pin' => $pin,
        ];
    }

    /**
     * Valores de `auth_methods` da página pública: o método do participante e, quando há
     * PIN, `sender_pin`.
     *
     * @return list<string>
     */
    public function authMethods(SignerContext $context): array
    {
        $methods = [$context->recipient->auth_method->value];

        if ($this->pins->requiredFor($context->recipient)) {
            $methods[] = 'sender_pin';
        }

        return $methods;
    }

    /**
     * Destino mascarado do código ("j•••@exemplo.com" ou "+55 •••••••5678") para a mensagem
     * "Enviamos um código para …".
     */
    public static function destination(SignerContext $context): string
    {
        return $context->recipient->auth_method->requiresPhone()
            ? PhoneE164::mask($context->recipient->phone)
            : $context->recipient->masked_email;
    }
}
