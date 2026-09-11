<?php

namespace App\Jobs\Envelopes;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Services\Envelopes\Finalization\EnvelopeFinalizer;
use App\Services\Envelopes\Finalization\ParticipantSignatureStage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Fim do prazo das assinaturas com certificado de participante (Fase 2 §2.12).
 *
 * Agendado (com `delay`) quando a finalização começa a esperar. No prazo, roda a finalização,
 * que vence os pedidos que não chegaram e conclui o envelope sem eles — o aceite eletrônico
 * de cada participante continua valendo. Se o prazo ainda não chegou (fila adiantada), volta
 * para a fila até lá. Só IDs no payload.
 */
class ApplyParticipantSignatureDeadline implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 20;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $envelopeId,
        public readonly int $organizationId,
    ) {
        $this->onQueue((string) config('assinavelox.queues.finalization', 'finalization'));
    }

    public function uniqueId(): string
    {
        return 'participant-signature-deadline:'.$this->envelopeId;
    }

    public function handle(ParticipantSignatureStage $stage, EnvelopeFinalizer $finalizer): void
    {
        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->find($this->envelopeId);

        if ($envelope === null || $envelope->status !== EnvelopeStatus::Finalizing) {
            return;
        }

        $deadline = $stage->nextDeadline($this->envelopeId);

        if ($deadline !== null && $deadline->isFuture()) {
            $this->release(max(1, (int) ceil(Carbon::now()->diffInSeconds($deadline, true))));

            return;
        }

        $finalizer->handle($this->envelopeId, $this->organizationId);
    }
}
