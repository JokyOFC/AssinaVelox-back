<?php

namespace App\Jobs\Envelopes;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Services\Envelopes\Finalization\EnvelopeFinalizer;
use App\Services\Envelopes\Finalization\Exceptions\FinalizationException;
use App\Services\Signing\SignerAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finalização do envelope (arquitetura §5, item 7). Fila `finalization`.
 *
 * Só **ids** viajam no payload: o job recarrega tudo do banco. Passar o model serializado
 * convidaria a decidir com dados velhos — entre o disparo e o processamento o envelope pode
 * ter sido recusado, expirado ou já finalizado por outro worker.
 *
 * ## Unicidade e idempotência (duas coisas diferentes)
 *
 * `ShouldBeUnique` por envelope evita **dois workers ao mesmo tempo** na mesma finalização.
 * Ele não basta: a trava tem prazo (`uniqueFor`) e o worker pode morrer sem liberá-la. A
 * garantia real é a **idempotência** do `EnvelopeFinalizer` — cada etapa verifica se o
 * artefato já existe antes de recriá-lo, e a transição para `completed` acontece sob lock,
 * só a partir de `finalizing`. Repetir o job não duplica versões nem conclui duas vezes.
 *
 * ## Falha
 *
 * Três tentativas com backoff crescente. Falhar é o comportamento correto quando a
 * assinatura criptográfica prometida não pôde ser aplicada: o envelope permanece em
 * `finalizing` (a interface diz "Em andamento · finalizando"), nada é concluído pela
 * metade e nenhuma assinatura é simulada. Esgotadas as tentativas, `failed()` grava
 * `envelope.finalization_failed` na trilha e registra o incidente em nível `error` — que é
 * o gancho de alerta operacional.
 *
 * O `finalization_key` (`envelopes.finalization_key`, gravado quando o último aceite chega)
 * é a chave de correlação da finalização entre tentativas; ele viaja no payload apenas como
 * dado de log e é reconciliado a partir do banco.
 */
class FinalizeEnvelope implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tentativas antes de considerar o job falho. */
    public int $tries = 3;

    /** Trava de unicidade: liberada por tempo caso o worker morra sem finalizar. */
    public int $uniqueFor = 1800;

    /** Teto absoluto de execução de uma tentativa (compose + DOMPDF + append + sign). */
    public int $timeout = 600;

    public function __construct(
        public readonly int $envelopeId,
        public readonly int $organizationId,
        public readonly ?string $finalizationKey = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('assinavelox.queues.finalization', 'finalization'));
    }

    public function uniqueId(): string
    {
        return 'envelope-finalization:'.$this->envelopeId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(EnvelopeFinalizer $finalizer, LoggerInterface $logger): void
    {
        $correlationId = $this->correlationId ?? (string) Str::ulid();

        $outcome = $finalizer->handle($this->envelopeId, $this->organizationId, $correlationId);

        $logger->info('Finalização do envelope processada.', [
            'envelope_id' => $this->envelopeId,
            'status' => $outcome->status,
            'signature_status' => $outcome->signatureStatus->value,
            'steps' => $outcome->steps,
            'finalization_key' => $this->finalizationKey,
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * Última tentativa esgotada. O envelope **continua** em `finalizing`: não existe estado
     * "falhou a finalização" na máquina de estados, e inventar um esconderia do operador
     * que há um documento aguardando. O que se faz é registrar e alertar.
     */
    public function failed(Throwable $exception): void
    {
        $logger = app(LoggerInterface::class);

        $code = $exception instanceof FinalizationException ? $exception->errorCode : 'unexpected_error';

        $logger->error('Finalização do envelope falhou definitivamente.', [
            'envelope_id' => $this->envelopeId,
            'organization_id' => $this->organizationId,
            'error_code' => $code,
            'exception' => $exception::class,
            'message' => Str::limit($exception->getMessage(), 500, ''),
            'finalization_key' => $this->finalizationKey,
            'correlation_id' => $this->correlationId,
            'alert' => 'envelope_finalization_failed',
        ]);

        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()
            ->whereKey($this->envelopeId)
            ->where('organization_id', $this->organizationId)
            ->first();

        if ($envelope === null || $envelope->status !== EnvelopeStatus::Finalizing) {
            return;
        }

        try {
            SignerAudit::system($envelope, AuditEventType::EnvelopeFinalizationFailed, [
                'error_code' => $code,
                'attempts' => $this->tries,
            ], null, $this->correlationId);
        } catch (Throwable $auditException) {
            $logger->error('Finalização: não foi possível registrar a falha na trilha.', [
                'envelope_id' => $this->envelopeId,
                'exception' => $auditException::class,
            ]);
        }
    }
}
