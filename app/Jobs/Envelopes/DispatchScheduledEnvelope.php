<?php

namespace App\Jobs\Envelopes;

use App\Services\Envelopes\Sending\ScheduledSend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Disparo de um envio agendado — despachado por `envelopes:dispatch-scheduled`.
 *
 * Uma tentativa só: o disparo começa reivindicando o agendamento (UPDATE condicional), então
 * uma retentativa não teria o que reivindicar. Falha inesperada → `failed()` registra o
 * cancelamento e avisa o remetente, em vez de o agendamento sumir em silêncio.
 */
class DispatchScheduledEnvelope implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $envelopeId,
        public readonly string $scheduledFor,
    ) {
        $this->onQueue((string) config('assinavelox.queues.default', 'default'));
    }

    public function uniqueId(): string
    {
        return 'scheduled-send:'.$this->envelopeId.':'.$this->scheduledFor;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(ScheduledSend $scheduler): void
    {
        $scheduler->fire($this->envelopeId, $this->scheduledFor);
    }

    public function failed(?Throwable $exception): void
    {
        app(ScheduledSend::class)->markDispatchFailed($this->envelopeId, $this->scheduledFor);
    }
}
