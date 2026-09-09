<?php

namespace App\Listeners\Envelopes;

use App\Events\EnvelopeReadyForFinalization;
use App\Jobs\Envelopes\FinalizeEnvelope;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Consome o gancho deixado pelo incremento 3: o último aceite exigido chegou, o envelope
 * está em `finalizing` e agora existe quem finaliza.
 *
 * O listener é **síncrono e trivial de propósito** — ele só enfileira. Toda a decisão fica
 * no job, que roda na fila `finalization` com unicidade por envelope, retentativa e
 * idempotência. Um listener em fila acrescentaria um segundo lugar onde a mensagem pode se
 * perder, sem nenhum ganho.
 *
 * ## Por que a falha do despacho é engolida (e só ela)
 *
 * Com um driver de fila real (`database` em dev, Redis/Horizon em produção), `dispatch()`
 * apenas grava a mensagem: a finalização acontece em outro processo e uma falha dela nunca
 * chega até aqui. Com o driver `sync` — o padrão da suíte de testes e de algumas
 * instalações pequenas — o job roda **dentro da requisição do signatário**, e aí uma falha
 * de finalização viraria um HTTP 500 para quem acabou de assinar, depois de o aceite já
 * estar commitado. Isso trocaria uma tela de confirmação legítima por um erro.
 *
 * O que se perde ao capturar: nada de estado. O job já registrou a falha por conta própria
 * (`failed()` grava `envelope.finalization_failed` e loga em nível `error`), o envelope
 * continua em `finalizing`, nenhum arquivo é publicado e a interface segue dizendo "Em
 * andamento · finalizando". Reprocessar é despachar o job de novo — ele é idempotente.
 *
 * Registrado por descoberta automática de eventos (Laravel varre `app/Listeners`).
 */
class StartEnvelopeFinalization
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handle(EnvelopeReadyForFinalization $event): void
    {
        try {
            FinalizeEnvelope::dispatch(
                $event->envelopeId,
                $event->organizationId,
                $event->finalizationKey,
                $event->correlationId,
            );
        } catch (Throwable $exception) {
            $this->logger->error('Finalização: o despacho do job falhou; o envelope continua em finalizing.', [
                'envelope_id' => $event->envelopeId,
                'organization_id' => $event->organizationId,
                'finalization_key' => $event->finalizationKey,
                'correlation_id' => $event->correlationId,
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 500, ''),
                'alert' => 'envelope_finalization_dispatch_failed',
            ]);
        }
    }
}
