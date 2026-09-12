<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CursorPageRequest;
use App\Http\Requests\Api\V1\GenerateEnvelopeFromTemplateRequest;
use App\Http\Resources\Api\V1\EnvelopeResource;
use App\Http\Resources\Api\V1\TemplateResource;
use App\Models\Template;
use App\Services\Api\ApiContext;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Services\Templates\CreateEnvelopeFromTemplate;
use App\Services\Templates\TemplatesFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Modelos — API v1. Só existem com a flag `templates` ligada para a organização (senão 404,
 * como na interface); a geração usa o MESMO serviço de "Usar modelo".
 */
class TemplateController extends Controller
{
    /**
     * Listar modelos
     *
     * Modelos não arquivados da organização, com paginação por cursor. Ability: `templates:read`.
     */
    public function index(CursorPageRequest $request): AnonymousResourceCollection
    {
        TemplatesFeature::ensure(ApiContext::organization());
        Gate::authorize('viewAny', Template::class);

        $page = Template::query()
            ->with('currentVersion')
            ->whereNull('archived_at')
            ->orderByDesc('ulid')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return TemplateResource::collection($page);
    }

    /**
     * Detalhar modelo
     *
     * Variáveis (chave, tipo, obrigatoriedade) e papéis (ULID) esperados na geração.
     * Ability: `templates:read`.
     */
    public function show(Template $template): TemplateResource
    {
        TemplatesFeature::ensure(ApiContext::organization());
        Gate::authorize('view', $template);

        return (new TemplateResource($template->load('currentVersion')))->detailed();
    }

    /**
     * Gerar documento a partir do modelo
     *
     * Cria um rascunho com o arquivo preenchido, os participantes por papel e os campos do
     * modelo. Corpo: `title`, `values` (`{chave: valor}`) e `participants`
     * (`{ulid_do_papel: {name, email}}`). Exige `Idempotency-Key`. Ability: `templates:use`.
     */
    public function generate(GenerateEnvelopeFromTemplateRequest $request, Template $template, CreateEnvelopeFromTemplate $creator): JsonResponse
    {
        TemplatesFeature::ensure(ApiContext::organization());

        if (! $template->isUsable()) {
            throw ApiProblemException::conflict('template-unavailable', 'Este modelo está arquivado ou sem versão e não pode ser usado.');
        }

        Gate::authorize('use', $template);

        $envelope = $creator->handle($template, ApiContext::membership()->user, $request->payload(), $request);

        return (new EnvelopeResource($envelope->load(EnvelopeResource::relations())))
            ->detailed()
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('api.v1.envelopes.show', ['envelope' => $envelope->ulid]));
    }
}
