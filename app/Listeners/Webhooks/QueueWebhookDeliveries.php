<?php

namespace App\Listeners\Webhooks;

use App\Models\AuditEvent;
use App\Services\Webhooks\WebhookFanOut;
use App\Services\Webhooks\WebhooksFeature;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gancho `eloquent.created` de AuditEvent → entregas de webhook (WebhookFanOut).
 *
 * Registrado explicitamente por App\Services\Webhooks\WebhooksServiceProvider (o método não se
 * chama `handle` justamente para a descoberta automática de eventos não registrá-lo de novo).
 *
 * Nunca lança: o evento foi gravado por uma operação de domínio (um aceite, um envio) e um
 * problema no webhook não pode desfazê-la nem virar erro para quem a fez.
 */
final class QueueWebhookDeliveries
{
    public function onAuditEventCreated(AuditEvent $event): void
    {
        if (! WebhooksFeature::globallyEnabled()) {
            return;
        }

        try {
            app(WebhookFanOut::class)->handle($event);
        } catch (Throwable $exception) {
            Log::error('webhooks.fan_out_failed', [
                'audit_event' => $event->ulid,
                'organization_id' => $event->organization_id,
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }
}
