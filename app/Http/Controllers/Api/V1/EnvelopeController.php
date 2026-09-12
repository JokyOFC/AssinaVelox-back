<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListEnvelopesRequest;
use App\Http\Requests\Api\V1\StoreEnvelopeRequest;
use App\Http\Resources\Api\V1\EnvelopeResource;
use App\Models\Envelope;
use App\Models\Folder;
use App\Services\Api\ApiContext;
use App\Services\Api\ApiEnvelopeAccess;
use App\Services\Api\EnvelopeDrafts;
use App\Services\Organizations\EnvelopeVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Documentos (envelopes) — API v1.
 *
 * A visibilidade é a MESMA da interface (App\Services\Organizations\EnvelopeVisibility): um
 * token criado por quem não tem `view_all_envelopes` vê só o que essa pessoa veria na tela.
 * Envelope de outra organização, excluído ou invisível → 404.
 */
class EnvelopeController extends Controller
{
    /**
     * Listar documentos
     *
     * Documentos visíveis para o token, do mais recente para o mais antigo, com paginação por
     * cursor (`meta.next_cursor`). Filtros: `status` (um ou vários, separados por vírgula),
     * `folder` (ULID), `q` (título ou número AV-00012), `created_after`, `created_before`,
     * `updated_after` (ISO-8601). Ability: `envelopes:read`.
     */
    public function index(ListEnvelopesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Envelope::class);

        $query = EnvelopeVisibility::envelopes(ApiContext::membership())->with(EnvelopeResource::relations());

        $this->applyFilters($query, $request);

        $page = $query->orderByDesc('ulid')->cursorPaginate($request->perPage())->withQueryString();

        return EnvelopeResource::collection($page);
    }

    /**
     * Criar rascunho
     *
     * Cria um documento em rascunho com os padrões da organização (ordem, prazo, rubrica). Em
     * seguida: enviar o arquivo, definir participantes e campos e enviar. Exige o cabeçalho
     * `Idempotency-Key`. Ability: `envelopes:write`.
     */
    public function store(StoreEnvelopeRequest $request, EnvelopeDrafts $drafts): JsonResponse
    {
        Gate::authorize('create', Envelope::class);

        $envelope = $drafts->create(ApiContext::organization(), ApiContext::membership()->user, $request->payload());

        return (new EnvelopeResource($envelope->load(EnvelopeResource::relations())))
            ->detailed()
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('api.v1.envelopes.show', ['envelope' => $envelope->ulid]));
    }

    /**
     * Detalhar documento
     *
     * Situação, arquivos (com resumos SHA-256), participantes e links. Ability: `envelopes:read`.
     */
    public function show(Envelope $envelope): EnvelopeResource
    {
        ApiEnvelopeAccess::ensureVisible($envelope);

        return (new EnvelopeResource($envelope->load(EnvelopeResource::relations())))->detailed();
    }

    /**
     * @param  Builder<Envelope>  $query
     */
    private function applyFilters(Builder $query, ListEnvelopesRequest $request): void
    {
        $statuses = (array) $request->validated('status', []);

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $folder = $request->validated('folder');

        if (is_string($folder) && $folder !== '') {
            $query->where('folder_id', Folder::query()->where('ulid', $folder)->value('id'));
        }

        $search = trim((string) $request->validated('q', ''));

        if ($search !== '') {
            $like = '%'.$search.'%';
            $numeric = ltrim(preg_replace('/^av-?/i', '', $search) ?? '', '0');

            $query->where(function (Builder $inner) use ($like, $numeric): void {
                $inner->where('title', 'like', $like);

                if ($numeric !== '' && ctype_digit($numeric)) {
                    $inner->orWhere('number', (int) $numeric);
                }
            });
        }

        foreach (['created_after' => ['created_at', '>='], 'created_before' => ['created_at', '<='], 'updated_after' => ['updated_at', '>=']] as $key => [$column, $operator]) {
            $value = $request->validated($key);

            if (is_string($value) && $value !== '') {
                $query->where($column, $operator, Carbon::parse($value, 'UTC')->utc());
            }
        }
    }
}
