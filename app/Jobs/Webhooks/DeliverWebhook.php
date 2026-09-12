<?php

namespace App\Jobs\Webhooks;

use App\Services\Webhooks\WebhookDeliverer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uma tentativa de entrega de webhook. O payload do job carrega só o id interno da entrega e o
 * gatilho — nada de segredo, corpo ou URL na fila (T10).
 *
 * `tries = 1`: as retentativas são NOSSAS (backoff em `webhook_deliveries.next_retry_at` +
 * varredura `webhooks:retry`), não da fila. O job nunca lança: a falha fica no histórico.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Primeira tentativa, despachada depois do commit que criou a entrega. */
    public const TRIGGER_INITIAL = 'initial';

    /** Retentativa agendada, despachada pela varredura. */
    public const TRIGGER_RETRY = 'retry';

    /** Reenvio pedido na tela. */
    public const TRIGGER_MANUAL = 'manual';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $deliveryId,
        public readonly string $trigger = self::TRIGGER_INITIAL,
    ) {
        $this->onQueue((string) config('assinavelox.webhooks.queue', 'default'));
    }

    public function handle(WebhookDeliverer $deliverer): void
    {
        try {
            $deliverer->attempt($this->deliveryId, $this->trigger);
        } catch (Throwable $exception) {
            Log::error('webhooks.delivery_job_failed', [
                'delivery_id' => $this->deliveryId,
                'trigger' => $this->trigger,
                'exception' => $exception::class,
            ]);
        }
    }
}
