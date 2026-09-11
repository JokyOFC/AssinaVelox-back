<?php

namespace App\Jobs\Envelopes;

use App\Services\Envelopes\Finalization\EnvelopeFinalizer;
use App\Services\Signing\Certificates\EnvelopeSigningLock;
use App\Services\Signing\Certificates\Exceptions\ParticipantSignatureFailed;
use App\Services\Signing\Certificates\ParticipantSignatureApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aplica a assinatura com o certificado do PARTICIPANTE (Fase 2 §2.12). Fila `finalization`.
 *
 * ## Um gravador por envelope
 *
 * - `WithoutOverlapping(envelope:{id}:sign)` — dois jobs do MESMO envelope não ocupam dois
 *   workers: o segundo volta para a fila e espera (`releaseAfter`);
 * - `ShouldBeUnique` por PEDIDO — o mesmo pedido não é enfileirado duas vezes;
 * - dentro, {@see ParticipantSignatureApplier} ainda toma o lock de cache do envelope (o
 *   mesmo da finalização) e grava com compare-and-set: mesmo com lock vencido, revisão irmã
 *   não entra.
 *
 * ## Payload
 *
 * Só IDs (e o correlation id). **Nunca** PFX nem senha: o material está cifrado num
 * arquivo temporário de prazo curto, apagado ao ser consumido. Serializar este job não
 * expõe segredo nenhum (há teste que confere).
 *
 * ## Depois de aplicar
 *
 * A finalização é retomada aqui mesmo (`EnvelopeFinalizer::handle`, idempotente): com esta
 * assinatura o envelope pode estar completo — ou continuar esperando pelos demais.
 */
class ApplyParticipantSignature implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Voltas à fila esperando o lock ou a base contam como tentativas. */
    public int $tries = 40;

    public int $maxExceptions = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $requestId,
        public readonly int $envelopeId,
        public readonly int $organizationId,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('assinavelox.queues.finalization', 'finalization'));
    }

    public function uniqueId(): string
    {
        return 'participant-signature:'.$this->requestId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(EnvelopeSigningLock::key($this->envelopeId)))
                ->releaseAfter(15)
                ->expireAfter(900),
        ];
    }

    public function handle(ParticipantSignatureApplier $applier, EnvelopeFinalizer $finalizer, LoggerInterface $logger): void
    {
        $correlationId = $this->correlationId ?? (string) Str::ulid();

        $outcome = $this->attempt($applier, $logger, $correlationId);

        if ($outcome === ParticipantSignatureApplier::WAITING_BASE) {
            // A base congelada ainda não existe: quem a monta é a finalização (que, vendo este
            // pedido pendente, prepara a base e espera). Roda-a e tenta de novo uma vez.
            $this->resume($finalizer, $logger, $correlationId);
            $outcome = $this->attempt($applier, $logger, $correlationId);

            if ($outcome === ParticipantSignatureApplier::WAITING_BASE) {
                $this->release(10);

                return;
            }
        }

        if ($outcome === null) {
            return;
        }

        if ($outcome === ParticipantSignatureApplier::SKIPPED) {
            return;
        }

        $this->resume($finalizer, $logger, $correlationId);
    }

    /**
     * Uma tentativa de aplicação. `null` = não há o que fazer agora (lock ocupado: o job
     * voltou para a fila; ou a aplicação falhou e o pedido já está `failed`).
     */
    private function attempt(ParticipantSignatureApplier $applier, LoggerInterface $logger, string $correlationId): ?string
    {
        try {
            return $applier->apply($this->requestId, $correlationId);
        } catch (LockTimeoutException) {
            // Outro gravador está no envelope: espera a vez.
            $this->release(15);

            return null;
        } catch (ParticipantSignatureFailed $exception) {
            // O pedido já foi marcado `failed` e o material destruído: sem PFX, não há o que
            // tentar de novo. O participante pode reenviar dentro do prazo.
            $logger->warning('A1 do participante: aplicação falhou.', [
                'request_id' => $this->requestId,
                'error_code' => $exception->errorCode,
                'correlation_id' => $correlationId,
            ]);

            return null;
        }
    }

    /**
     * Retoma a finalização (idempotente): monta a base, espera pelos pendentes ou conclui.
     */
    private function resume(EnvelopeFinalizer $finalizer, LoggerInterface $logger, string $correlationId): void
    {
        try {
            $finalizer->handle($this->envelopeId, $this->organizationId, $correlationId);
        } catch (Throwable $exception) {
            $logger->error('A1 do participante: assinatura aplicada, mas a retomada da finalização falhou.', [
                'envelope_id' => $this->envelopeId,
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 300, ''),
                'correlation_id' => $correlationId,
            ]);

            try {
                FinalizeEnvelope::dispatch($this->envelopeId, $this->organizationId, null, $correlationId);
            } catch (Throwable) {
                // O alerta acima é o gancho operacional; o envelope continua em finalizing.
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        app(ParticipantSignatureApplier::class)->abandon($this->requestId, 'job_failed');
    }
}
