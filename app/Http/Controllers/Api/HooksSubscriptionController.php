<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\Api\ApiContext;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Services\RestHooks\RestHooksFeature;
use App\Services\RestHooks\RestHookSubscriptions;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * REST Hooks (roadmap §2.17; docs/fase-2/integracoes-no-code.md) — assinatura dinâmica de
 * webhooks pela API, o padrão que Zapier, Make e n8n usam para gatilhos "instantâneos".
 *
 * Camadas, na ordem: grupo `api` (flag da API, token, limites) → `api.ability:webhooks:manage`
 * (ability no token E `manage_integrations` do criador agora) → flag `rest_hooks` (404) →
 * WebhookEndpointPolicy com o criador do token → motor de webhooks (SSRF, segredo, HMAC).
 *
 * Cada assinatura pertence ao token que a criou: outro token, mesmo da organização, recebe 404.
 */
class HooksSubscriptionController extends Controller
{
    public function __construct(private readonly RestHookSubscriptions $subscriptions) {}

    /**
     * Assinaturas ativas deste token.
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $token = ApiContext::token($request) ?? throw new AuthenticationException;
        Gate::authorize('viewAny', WebhookEndpoint::class);

        $items = RestHookSubscriptions::forToken($token)->get()
            ->map(static fn (WebhookEndpoint $endpoint): array => RestHookSubscriptions::present($endpoint))
            ->values()
            ->all();

        return response()->json([
            'data' => $items,
            'meta' => ['limit' => RestHookSubscriptions::maxPerToken(), 'count' => count($items)],
        ]);
    }

    /**
     * Assina um evento (ou vários) numa URL HTTPS pública.
     *
     * Corpo: `target_url` (obrigatório) e `event` (um valor do catálogo ou `*`) OU `events[]`.
     * 201 com `secret` (a ÚNICA vez em que ele aparece) na criação; 200 sem `secret` quando a
     * mesma assinatura já existe para este token (repetição segura).
     *
     * A resposta de criação sai de propósito como resposta HTTP simples (não `JsonResponse`):
     * o armazenamento de `Idempotency-Key` guarda o corpo de respostas JSON por 24 h, e o
     * segredo não pode ficar gravado em claro. A repetição com a mesma chave é resolvida aqui
     * mesmo (mesmo token, URL e eventos → a assinatura existente, sem segredo).
     */
    public function store(Request $request): Response
    {
        $this->ensureEnabled();

        $token = ApiContext::token($request) ?? throw new AuthenticationException;
        $membership = ApiContext::membership();
        Gate::authorize('create', WebhookEndpoint::class);

        $accepted = RestHookSubscriptions::acceptedEvents();
        $validated = $request->validate([
            'target_url' => ['required', 'string', 'max:2048'],
            'event' => ['required_without:events', 'nullable', 'string', Rule::in($accepted)],
            'events' => ['required_without:event', 'nullable', 'array', 'min:1', 'max:'.count($accepted)],
            'events.*' => ['string', 'distinct', Rule::in($accepted)],
        ], [
            'event.in' => 'Evento desconhecido. Consulte GET /api/v1/webhook-events.',
            'events.*.in' => 'Evento desconhecido. Consulte GET /api/v1/webhook-events.',
        ], [
            'target_url' => 'URL de destino',
            'event' => 'evento',
            'events' => 'eventos',
        ]);

        $events = isset($validated['events']) && is_array($validated['events']) && $validated['events'] !== []
            ? array_values(array_map('strval', $validated['events']))
            : [(string) $validated['event']];

        $result = $this->subscriptions->subscribe(
            ApiContext::organization(),
            $membership,
            $token,
            trim((string) $validated['target_url']),
            $events,
        );

        $body = ['data' => RestHookSubscriptions::present($result['endpoint'], $result['secret'])];

        if (! $result['created']) {
            $body['meta'] = ['existing' => true, 'note' => 'Esta assinatura já existia para este token. O segredo só é mostrado na criação; para um novo, rotacione em Integrações → Webhooks.'];
        }

        return new Response(
            (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $result['created'] ? 201 : 200,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
                'Location' => url('/api/v1/webhook-subscriptions/'.$result['endpoint']->ulid),
            ],
        );
    }

    /**
     * Remove a assinatura (o "unsubscribe" do REST Hooks). 204; 404 se não for deste token.
     */
    public function destroy(Request $request, string $subscription): Response
    {
        $this->ensureEnabled();

        $token = ApiContext::token($request) ?? throw new AuthenticationException;
        $endpoint = $this->subscriptions->findForToken($token, $subscription)
            ?? throw new NotFoundHttpException;

        Gate::authorize('delete', $endpoint);

        $this->subscriptions->unsubscribe($endpoint);

        return response()->noContent();
    }

    /**
     * @throws ApiProblemException
     */
    private function ensureEnabled(): void
    {
        if (! RestHooksFeature::enabled(ApiContext::organization())) {
            throw new NotFoundHttpException;
        }
    }
}
