<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Envelopes\SyncFieldsRequest;
use App\Models\Envelope;
use App\Services\Api\ApiEnvelopeAccess;

/**
 * PUT /api/v1/envelopes/{envelope}/fields — substitui os campos posicionados.
 *
 * Mesmo contrato do passo 3 do wizard (App\Http\Requests\Envelopes\SyncFieldsRequest +
 * App\Services\Envelopes\FieldSync): geometria em frações [0,1] da página exibida, origem no
 * canto superior esquerdo; `recipient_id` e `document_id` são ULIDs do próprio envelope.
 *
 * Envelope invisível para o criador do token → 404 (antes da validação); visível sem `update`
 * → 403.
 */
class SyncEnvelopeFieldsRequest extends SyncFieldsRequest
{
    public function authorize(): bool
    {
        $envelope = $this->route('envelope');

        if ($envelope instanceof Envelope) {
            ApiEnvelopeAccess::ensureVisible($envelope);
        }

        return parent::authorize();
    }

    /**
     * As MESMAS regras da interface; só documenta no OpenAPI que `page` é inteiro (a regra
     * herdada é `required`, sem tipo, e o Scramble a descrevia como texto).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return array_merge($rules, [
            /** @var int Página do documento exibido, a partir de 1. */
            'fields.*.page' => $rules['fields.*.page'],
        ]);
    }
}
