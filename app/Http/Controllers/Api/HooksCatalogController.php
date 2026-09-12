<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\Api\ApiContext;
use App\Services\RestHooks\RestHookSamples;
use App\Services\RestHooks\RestHooksFeature;
use App\Services\Webhooks\WebhookEventType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Catálogo de eventos e payloads de EXEMPLO para os conectores no-code (REST Hooks,
 * roadmap §2.17). O exemplo nunca usa dado real: é montado por App\Services\RestHooks\
 * RestHookSamples, com ULIDs fictícios e a mesma forma das entregas de verdade.
 */
class HooksCatalogController extends Controller
{
    public function __construct(private readonly RestHookSamples $samples) {}

    /**
     * Eventos que podem ser assinados (`*` = todos, inclusive os que forem criados depois).
     */
    public function events(): JsonResponse
    {
        $this->ensureEnabled();
        Gate::authorize('viewAny', WebhookEndpoint::class);

        return response()->json([
            'data' => array_map(static fn (WebhookEventType $type): array => [
                'object' => 'webhook_event_type',
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ], WebhookEventType::subscribable()),
        ]);
    }

    /**
     * Payload de exemplo de um evento, no formato `{data: [payload]}` — lista com um item, que
     * é o que os editores de gatilho esperam para mapear campos. Nenhum dado real.
     */
    public function sample(string $event): JsonResponse
    {
        $this->ensureEnabled();
        Gate::authorize('viewAny', WebhookEndpoint::class);

        $type = WebhookEventType::tryFrom($event);

        if ($type === null || $type === WebhookEventType::Ping) {
            throw new NotFoundHttpException;
        }

        return response()->json([
            'data' => [$this->samples->forEvent($type)],
            'meta' => [
                'sample' => true,
                'note' => 'Exemplo com identificadores fictícios. As entregas reais têm a mesma forma.',
            ],
        ]);
    }

    private function ensureEnabled(): void
    {
        if (! RestHooksFeature::enabled(ApiContext::organization())) {
            throw new NotFoundHttpException;
        }
    }
}
