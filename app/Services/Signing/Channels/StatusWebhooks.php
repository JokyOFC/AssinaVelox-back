<?php

namespace App\Services\Signing\Channels;

use App\Enums\DeliveryChannel;
use App\Enums\WebhookProcessingStatus;
use App\Integrations\Contracts\Messaging\ChannelStatusEvent;
use App\Integrations\Contracts\Messaging\IncomingStatusCallback;
use App\Integrations\Contracts\MessagingProvider;
use App\Models\ChannelStatusReceipt;
use App\Models\DeliveryAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de status de SMS/WhatsApp (POST /webhooks/sms/status e /webhooks/whatsapp/status).
 *
 * 1. Provedor sem webhook ativo (produção desabilitada, simulador sem segredo) → **503** com
 *    o motivo. Nada é lido.
 * 2. Assinatura ausente, inválida ou fora da janela → **401** genérico, sem gravar recibo.
 *    O corpo nunca é interpretado antes da assinatura conferir.
 * 3. Cada evento vira um recibo com fingerprint ÚNICO: uma reentrega (ou replay dentro da
 *    janela) reencontra a linha e **não reprocessa**.
 * 4. A tentativa é localizada por (canal, provedor, provider_message_id) — nunca por dado do
 *    payload que não seja o id que o próprio provedor nos devolveu no envio.
 */
final class StatusWebhooks
{
    public function __construct(
        private readonly ChannelAvailability $availability,
        private readonly DeliveryStatusUpdater $updater,
    ) {}

    public function handle(DeliveryChannel $channel, Request $request): JsonResponse
    {
        $provider = $this->availability->provider($channel);

        if ($provider === null || ! $provider->acceptsStatusCallbacks()) {
            return response()->json([
                'error' => 'channel_status_disabled',
                'reason' => sprintf(
                    'O webhook de status de %s está desativado: o provedor não está configurado nesta instalação.',
                    $channel->label(),
                ),
            ], 503);
        }

        $callback = new IncomingStatusCallback((string) $request->getContent(), self::headers($request));
        $verification = $provider->verifyStatusCallback($callback);

        if (! $verification->valid) {
            Log::warning('channels.status_webhook.rejected', [
                'channel' => $channel->value,
                'provider' => $provider->name(),
                'reason' => $verification->reason,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $events = $provider->parseStatusCallback($callback);

        if ($events === []) {
            return response()->json(['received' => true, 'ignored' => 'no_events']);
        }

        $counts = ['processed' => 0, 'duplicates' => 0, 'ignored' => 0];

        foreach ($events as $event) {
            $counts[$this->process($provider, $channel, $event)]++;
        }

        return response()->json(['received' => true] + $counts);
    }

    /**
     * @return 'processed'|'duplicates'|'ignored'
     */
    private function process(MessagingProvider $provider, DeliveryChannel $channel, ChannelStatusEvent $event): string
    {
        $fingerprint = self::fingerprint($event);

        try {
            $receipt = ChannelStatusReceipt::query()->firstOrCreate(
                ['provider' => $provider->name(), 'event_fingerprint' => $fingerprint],
                [
                    'channel' => $channel,
                    'provider_message_id' => $event->providerMessageId,
                    'reported_status' => $event->status->value,
                    'payload' => [
                        'event_id' => $event->eventId,
                        'message_id' => $event->providerMessageId,
                        'status' => $event->status->value,
                        'detail' => $event->detail,
                    ],
                    'signature_valid' => true,
                    'is_simulated' => $provider->isSimulated(),
                    'occurred_at' => $event->occurredAt !== null ? Carbon::instance($event->occurredAt) : null,
                    'received_at' => Carbon::now(),
                    'processing_status' => WebhookProcessingStatus::Received,
                ],
            );
        } catch (QueryException) {
            // Corrida perdida com uma entrega simultânea do mesmo evento: a outra processa.
            return 'duplicates';
        }

        if (! $receipt->wasRecentlyCreated && $receipt->processing_status !== WebhookProcessingStatus::Failed) {
            return 'duplicates';
        }

        /** @var DeliveryAttempt|null $attempt */
        $attempt = DeliveryAttempt::withoutOrganizationScope()
            ->where('channel', $channel->value)
            ->where('provider', $provider->name())
            ->where('provider_message_id', $event->providerMessageId)
            ->latest('id')
            ->first();

        if ($attempt === null) {
            $receipt->forceFill([
                'processing_status' => WebhookProcessingStatus::Ignored,
                'processed_at' => Carbon::now(),
                'error' => 'unknown_message',
            ])->save();

            return 'ignored';
        }

        $outcome = $this->updater->apply($attempt, $event, [
            'source' => 'status_webhook',
            'receipt_id' => $receipt->getKey(),
            'simulated' => $provider->isSimulated(),
            'reported_status' => $event->status->value,
            'occurred_at' => $event->occurredAt?->format(DATE_ATOM),
        ]);

        $receipt->forceFill([
            'delivery_attempt_id' => $attempt->getKey(),
            'processing_status' => $outcome === 'ignored' ? WebhookProcessingStatus::Ignored : WebhookProcessingStatus::Processed,
            'processed_at' => Carbon::now(),
            'error' => $outcome === 'ignored' ? 'no_transition' : null,
        ])->save();

        return $outcome === 'ignored' ? 'ignored' : 'processed';
    }

    public static function fingerprint(ChannelStatusEvent $event): string
    {
        $parts = $event->eventId !== null
            ? ['event', $event->eventId]
            : ['message', $event->providerMessageId, $event->status->value, $event->occurredAt?->format(DATE_ATOM) ?? ''];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @return array<string, string>
     */
    private static function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return $headers;
    }
}
