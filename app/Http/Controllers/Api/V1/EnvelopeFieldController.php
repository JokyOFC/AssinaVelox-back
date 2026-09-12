<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncEnvelopeFieldsRequest;
use App\Http\Resources\Api\V1\FieldResource;
use App\Models\Envelope;
use App\Models\SigningField;
use App\Services\Api\ApiEnvelopeAccess;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Services\Envelopes\FieldSync;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Campos posicionados — API v1 (mesmo serviço do passo 3 do wizard).
 */
class EnvelopeFieldController extends Controller
{
    /**
     * Listar campos
     *
     * Geometria em frações [0,1] da página exibida. O valor preenchido não é devolvido.
     * Ability: `envelopes:read`.
     */
    public function index(Envelope $envelope): AnonymousResourceCollection
    {
        ApiEnvelopeAccess::ensureVisible($envelope);

        return FieldResource::collection($this->fields($envelope));
    }

    /**
     * Definir campos
     *
     * Substitui os campos (até 200). Página, participante e documento são conferidos contra o
     * arquivo processado; tamanhos mínimos por tipo valem como na interface. Só em rascunho.
     * Ability: `envelopes:write`.
     */
    public function sync(SyncEnvelopeFieldsRequest $request, Envelope $envelope, FieldSync $fields): AnonymousResourceCollection
    {
        if (! $envelope->status->isDraftLike()) {
            throw ApiProblemException::conflict('invalid-status', 'Os campos só podem ser definidos enquanto o documento é rascunho.', [
                'envelope_status' => $envelope->status->value,
            ]);
        }

        $fields->handle($envelope, $request->payload());

        return FieldResource::collection($this->fields($envelope));
    }

    /**
     * @return Collection<int, SigningField>
     */
    private function fields(Envelope $envelope): Collection
    {
        return SigningField::query()
            ->with(['recipient', 'documentVersion.document'])
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('page')
            ->orderBy('sort_order')
            ->get();
    }
}
