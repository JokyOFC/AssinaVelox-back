<?php

namespace App\Services\Signing\Channels;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Contracts\Messaging\ChannelMessage;
use App\Integrations\Dto\DeliveryReceipt;
use App\Integrations\Email\DeliveryContext;
use App\Integrations\Email\DeliveryRecorder;
use App\Models\DeliveryAttempt;
use App\Models\Recipient;
use App\Services\Signing\Exceptions\SigningRejectedException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Envio rastreado por SMS/WhatsApp: uma linha em `delivery_attempts` por tentativa, com o
 * mesmo DeliveryRecorder do e-mail (arquitetura §3.1).
 *
 * - `sent` só quando o provedor aceitou; `delivered` só por aviso assinado ou consulta;
 * - tempo esgotado (ConnectionException) e qualquer resposta inconclusiva → `unknown` (T5);
 * - a chave de idempotência enviada ao provedor é o ULID da linha: uma retentativa da mesma
 *   linha não gera segunda mensagem;
 * - `meta` guarda só template, finalidade e `simulated` — nunca o código nem o link.
 */
final class ChannelDelivery
{
    public function __construct(
        private readonly ChannelAvailability $availability,
        private readonly DeliveryRecorder $recorder,
        private readonly DeliveryStatusUpdater $updater,
        private readonly Repository $config,
    ) {}

    /**
     * Freio de custo: mensagens por SMS + WhatsApp por organização por dia.
     *
     * @throws SigningRejectedException
     */
    public function assertWithinOrganizationLimit(int $organizationId): void
    {
        $limit = (int) $this->config->get('assinavelox.channels.org_daily_limit', 500);

        if ($limit <= 0) {
            return;
        }

        $sentToday = DeliveryAttempt::withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->whereIn('channel', [DeliveryChannel::Sms->value, DeliveryChannel::Whatsapp->value])
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->count();

        if ($sentToday >= $limit) {
            throw SigningRejectedException::rateLimited(
                'O limite diário de mensagens por SMS e WhatsApp de quem enviou o documento foi atingido. Tente de novo amanhã ou fale com quem enviou.',
                max(60, (int) Carbon::now()->diffInSeconds(Carbon::now()->addDay()->startOfDay(), true)),
            );
        }
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  list<string>  $sensitive
     * @param  array<string, mixed>  $meta
     */
    public function send(
        Recipient $recipient,
        DeliveryChannel $channel,
        string $toE164,
        DeliveryPurpose $purpose,
        string $template,
        array $parameters,
        string $text,
        array $sensitive,
        string $correlationId,
        array $meta = [],
    ): DeliveryAttempt {
        $provider = $this->availability->provider($channel)
            ?? throw new InvalidArgumentException('O e-mail não sai por ChannelDelivery.');

        $context = new DeliveryContext(
            organizationId: (int) $recipient->organization_id,
            toAddress: $toE164,
            purpose: $purpose,
            correlationId: $correlationId,
            envelopeId: (int) $recipient->envelope_id,
            recipientId: $recipient->getKey(),
            channel: $channel,
            meta: $meta + ['template' => $template, 'simulated' => $provider->isSimulated()],
        );

        $already = $this->recorder->successFor($context);

        if ($already !== null) {
            return $already;
        }

        $attempt = $this->recorder->queue($context, $provider->name());

        $message = new ChannelMessage(
            toE164: $toE164,
            purpose: $purpose,
            template: $template,
            parameters: $parameters,
            text: $text,
            correlationId: $correlationId,
            idempotencyKey: $attempt->ulid,
            sensitive: $sensitive,
        );

        try {
            $receipt = $provider->send($message);
        } catch (ProviderDisabledException) {
            $receipt = DeliveryReceipt::failed($provider->name(), 'Provedor desabilitado nesta instalação: nada foi enviado.', $correlationId);
        } catch (ConnectionException) {
            $receipt = DeliveryReceipt::unknown(
                $provider->name(),
                'Tempo esgotado sem resposta do provedor: o resultado é desconhecido e não é tratado como sucesso.',
                $correlationId,
            );
        } catch (Throwable $exception) {
            // Só a classe: a mensagem de uma exceção de cliente HTTP pode ecoar o corpo enviado.
            Log::warning('channels.send.inconclusive', [
                'provider' => $provider->name(),
                'channel' => $channel->value,
                'exception' => $exception::class,
                'correlation_id' => $correlationId,
            ]);

            $receipt = DeliveryReceipt::unknown($provider->name(), 'Resposta inconclusiva do provedor: o resultado é desconhecido.', $correlationId);
        }

        return $this->recorder->record($attempt, $receipt);
    }

    /**
     * Consulta ao provedor antes de repetir um envio `unknown` (T5). Devolve a linha
     * (atualizada quando o provedor respondeu algo conclusivo).
     */
    public function refresh(DeliveryAttempt $attempt): DeliveryAttempt
    {
        $provider = $this->availability->provider($attempt->channel);

        if ($provider === null
            || $provider->name() !== $attempt->provider
            || $attempt->provider_message_id === null
            || ! $provider->isConfigured()) {
            return $attempt;
        }

        try {
            $event = $provider->status($attempt->provider_message_id, $attempt->correlation_id);
        } catch (Throwable) {
            return $attempt;
        }

        $this->updater->apply($attempt, $event, [
            'source' => 'status_query',
            'simulated' => $provider->isSimulated(),
            'reported_status' => $event->status->value,
        ]);

        return $attempt->refresh();
    }
}
