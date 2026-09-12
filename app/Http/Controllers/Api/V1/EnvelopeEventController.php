<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CursorPageRequest;
use App\Http\Resources\Api\V1\EventResource;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Services\Api\ApiEnvelopeAccess;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Eventos da trilha do documento — API v1 (somente leitura; a trilha é append-only).
 */
class EnvelopeEventController extends Controller
{
    /**
     * Eventos da trilha
     *
     * Em ordem cronológica, com paginação por cursor. Sem payload, IP ou user-agent.
     * Ability: `envelopes:read`.
     */
    public function index(CursorPageRequest $request, Envelope $envelope): AnonymousResourceCollection
    {
        ApiEnvelopeAccess::ensureVisible($envelope);

        $page = AuditEvent::query()
            ->with(['recipient', 'actorUser'])
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('ulid')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return EventResource::collection($page);
    }
}
