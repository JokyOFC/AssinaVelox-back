<?php

namespace App\Services\HubSpot\Jobs;

use App\Services\HubSpot\HubSpotActionHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Envio do envelope criado por uma ação do HubSpot quando o arquivo ainda estava sendo
 * preparado (HTML/DOCX convertendo). Tenta até `hubspot.send_attempts` vezes, espaçadas por
 * `hubspot.send_retry_seconds`; depois disso a execução fica "precisa de revisão". Payload
 * da fila: só o id da execução.
 */
final class SendHubSpotEnvelope implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public function __construct(public readonly int $executionId)
    {
        $this->tries = max(1, (int) config('assinavelox.hubspot.send_attempts', 20));
        $this->onQueue((string) config('assinavelox.hubspot.queue', 'default'));
    }

    public function handle(HubSpotActionHandler $handler): void
    {
        if ($handler->attemptSend($this->executionId, $this->attempts()) && $this->job !== null) {
            $this->release(max(5, (int) config('assinavelox.hubspot.send_retry_seconds', 30)));
        }
    }
}
