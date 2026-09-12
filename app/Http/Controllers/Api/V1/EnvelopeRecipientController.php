<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncEnvelopeRecipientsRequest;
use App\Http\Resources\Api\V1\RecipientResource;
use App\Models\Envelope;
use App\Services\Api\ApiEnvelopeAccess;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Services\Envelopes\RecipientSync;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Participantes do documento — API v1 (mesmo serviço do passo 2 do wizard).
 */
class EnvelopeRecipientController extends Controller
{
    /**
     * Situação dos participantes
     *
     * Na ordem da lista. Ability: `recipients:read`.
     */
    public function index(Envelope $envelope): AnonymousResourceCollection
    {
        ApiEnvelopeAccess::ensureVisible($envelope);

        return RecipientResource::collection($envelope->recipients()->get());
    }

    /**
     * Definir participantes
     *
     * Substitui a lista inteira (até 20). Para manter um participante, envie o `id` dele; linhas
     * sem `id` são criadas e quem ficar de fora é removido. Só em rascunho. Ability:
     * `envelopes:write`.
     */
    public function sync(SyncEnvelopeRecipientsRequest $request, Envelope $envelope, RecipientSync $recipients): AnonymousResourceCollection
    {
        if (! $envelope->status->isDraftLike()) {
            throw ApiProblemException::conflict('invalid-status', 'Os participantes só podem ser definidos enquanto o documento é rascunho.', [
                'envelope_status' => $envelope->status->value,
            ]);
        }

        $recipients->handle($envelope, $request->payload());

        return RecipientResource::collection($envelope->recipients()->get());
    }
}
