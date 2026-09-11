<?php

namespace App\Services\Signing\Channels;

use App\Enums\DeliveryStatus;
use App\Integrations\Contracts\Messaging\ChannelStatusEvent;
use App\Integrations\Email\DeliveryRecorder;
use App\Models\DeliveryAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Aplica em `delivery_attempts` uma situação informada PELO PROVEDOR (aviso assinado ou
 * consulta). Nunca é chamado com o retorno síncrono do envio.
 *
 * - `delivered` é definitivo: nada o rebaixa;
 * - `failed` só vale para quem ainda não foi entregue;
 * - `sent` só promove `queued`/`unknown`;
 * - `queued`/`unknown` não mudam nada.
 */
final class DeliveryStatusUpdater
{
    public function __construct(private readonly DeliveryRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $evidence  metadados da evidência (origem, recibo, simulado)
     * @return 'delivered'|'failed'|'sent'|'ignored'
     */
    public function apply(DeliveryAttempt $attempt, ChannelStatusEvent $event, array $evidence): string
    {
        if ($attempt->status === DeliveryStatus::Delivered) {
            return 'ignored';
        }

        $occurredAt = $event->occurredAt !== null ? Carbon::instance($event->occurredAt) : null;

        switch ($event->status) {
            case DeliveryStatus::Delivered:
                $this->recorder->markDelivered($attempt, $evidence, $occurredAt);

                return 'delivered';

            case DeliveryStatus::Failed:
            case DeliveryStatus::Bounced:
                $attempt->forceFill([
                    'status' => DeliveryStatus::Failed,
                    'error_message' => Str::limit($event->detail ?? 'O provedor informou falha na entrega.', 1000, ''),
                    'meta' => array_merge($attempt->meta ?? [], ['failure_evidence' => $evidence]),
                ])->save();

                return 'failed';

            case DeliveryStatus::Sent:
                if (! in_array($attempt->status, [DeliveryStatus::Queued, DeliveryStatus::Unknown], true)) {
                    return 'ignored';
                }

                $attempt->forceFill([
                    'status' => DeliveryStatus::Sent,
                    'sent_at' => $attempt->sent_at ?? $occurredAt ?? Carbon::now(),
                    'meta' => array_merge($attempt->meta ?? [], ['sent_evidence' => $evidence]),
                ])->save();

                return 'sent';

            default:
                return 'ignored';
        }
    }
}
