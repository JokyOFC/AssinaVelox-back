<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Envelopes\SyncRecipientsRequest;
use App\Models\Envelope;
use App\Services\Api\ApiEnvelopeAccess;

/**
 * PUT /api/v1/envelopes/{envelope}/recipients — substitui a lista inteira de participantes.
 *
 * Mesmo contrato e mesmas regras do passo 2 do wizard (App\Http\Requests\Envelopes\
 * SyncRecipientsRequest + App\Services\Envelopes\RecipientSync): para manter um participante,
 * envie o `id` (ULID) dele; linhas sem `id` são criadas; quem não vier é removido. O PIN, se
 * enviado, sai da entrada logo após a validação e nunca é devolvido.
 *
 * Envelope invisível para o criador do token → 404 (antes da validação); visível sem `update`
 * → 403.
 */
class SyncEnvelopeRecipientsRequest extends SyncRecipientsRequest
{
    public function authorize(): bool
    {
        $envelope = $this->route('envelope');

        if ($envelope instanceof Envelope) {
            ApiEnvelopeAccess::ensureVisible($envelope);
        }

        return parent::authorize();
    }
}
