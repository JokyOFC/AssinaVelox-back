<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelEnvelopeRequest;
use App\Http\Resources\Api\V1\EnvelopeResource;
use App\Models\Envelope;
use App\Services\Api\ApiEnvelopeAccess;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Services\Envelopes\Sending\CancelEnvelope;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Plans\Exceptions\SendingBlockedException;
use Illuminate\Support\Facades\Gate;

/**
 * Enviar e cancelar — API v1. Toda a regra (lock, congelamento da versão, código de
 * verificação, prazo, consumo do plano, convites, avisos) é dos serviços da interface.
 */
class EnvelopeLifecycleController extends Controller
{
    /**
     * Enviar para assinatura
     *
     * Exige o documento pronto (arquivo processado, participantes e campos obrigatórios) e
     * plano em dia. Exige `Idempotency-Key`: repetir com a mesma chave devolve a mesma
     * resposta sem enviar de novo. Ability: `envelopes:send`.
     */
    public function send(Envelope $envelope, SendEnvelope $sender): EnvelopeResource
    {
        ApiEnvelopeAccess::ensureVisible($envelope);
        Gate::authorize('send', $envelope);

        try {
            $result = $sender->handle($envelope);
        } catch (SendingBlockedException $exception) {
            throw ApiProblemException::conflict('sending-blocked', $exception->getMessage(), ['code' => $exception->errorCode], 'Envio bloqueado pelo plano');
        } catch (SendingException $exception) {
            throw $this->sendingProblem($exception);
        }

        return (new EnvelopeResource($result['envelope']->load(EnvelopeResource::relations())))
            ->detailed()
            ->additional(['meta' => ['invitations_sent' => $result['invitations']]]);
    }

    /**
     * Cancelar documento
     *
     * Encerra a coleta: participantes pendentes são cancelados e avisados, links revogados.
     * `Idempotency-Key` é opcional. Ability: `envelopes:send`.
     */
    public function cancel(CancelEnvelopeRequest $request, Envelope $envelope, CancelEnvelope $cancellation): EnvelopeResource
    {
        Gate::authorize('cancel', $envelope);

        if (! $envelope->status->isCancelable()) {
            throw ApiProblemException::conflict('invalid-status', 'Ação indisponível no status atual.', [
                'envelope_status' => $envelope->status->value,
            ]);
        }

        $result = $cancellation->handle($envelope, $request->reason());

        if (! $result['canceled']) {
            throw ApiProblemException::conflict('invalid-status', 'Ação indisponível no status atual.');
        }

        $fresh = $envelope->fresh() ?? $envelope;

        return (new EnvelopeResource($fresh->load(EnvelopeResource::relations())))
            ->detailed()
            ->additional(['meta' => ['recipients_notified' => $result['notified']]]);
    }

    private function sendingProblem(SendingException $exception): ApiProblemException
    {
        return match ($exception->errorCode) {
            'already_sent' => ApiProblemException::conflict('already-sent', $exception->getMessage()),
            'incomplete', 'no_sent_version' => ApiProblemException::conflict(
                'envelope-not-ready',
                $exception->getMessage(),
                ['issues' => array_values(array_map('strval', (array) ($exception->context['issues'] ?? [])))],
                'Documento não está pronto para envio',
            ),
            'dispatch_failed' => new ApiProblemException(
                503,
                'dispatch-failed',
                'Convites não emitidos',
                $exception->getMessage(),
                [],
                ['Retry-After' => 30],
            ),
            default => ApiProblemException::conflict('invalid-status', $exception->getMessage()),
        };
    }
}
