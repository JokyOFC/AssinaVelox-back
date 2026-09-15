<?php

namespace App\Http\Controllers;

use App\Enums\ApiAbility;
use App\Http\Resources\Api\ApiRequestLogResource;
use App\Http\Resources\Api\ApiTokenResource;
use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Organization;
use App\Services\Api\ApiFeature;
use App\Services\Api\ApiTokenManager;
use App\Services\CloudImport\CloudFileSources;
use App\Services\CloudImport\CloudImportFeature;
use App\Services\RestHooks\ApiReference;
use App\Services\RestHooks\IntegrationsNavigation;
use App\Services\RestHooks\RestHookSamples;
use App\Services\RestHooks\RestHookSubscriptions;
use App\Services\Webhooks\WebhookEventType;
use App\Services\Webhooks\WebhookPresenter;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * API e integrações (ROUTES §1.2 / §2.21; roadmap §2.15–§2.17).
 *
 * Com as flags `api_integrations` e `outbound_webhooks` desligadas (o padrão), tudo é o
 * placeholder da Fase 1: `index` renderiza `integrations/index` e `keys`/`logs` redirecionam
 * (302) para ele. Com alguma delas ligada, as telas reais exigem `manage_integrations`:
 *
 *  - `index` → Documentação (`integrations/docs`): guia rápido de autenticação, idempotência,
 *    erros, eventos e REST Hooks + link para a OpenAPI (Scramble, gate `viewApiDocs`);
 *  - `keys`  → Chaves (`integrations/keys`, só `api_integrations`);
 *  - `logs`  → Logs de requisições da API (`integrations/logs`, só `api_integrations`);
 *  - Webhooks → `integrations.webhooks.*` (D-HOOK, só `outbound_webhooks`).
 */
class IntegrationController extends Controller
{
    public function index(): Response
    {
        $organization = CurrentOrganization::instance()->get();

        if (! IntegrationsNavigation::anyEnabled($organization)) {
            return Inertia::render('integrations/index', [
                'feature' => 'api_integrations',
                'title' => 'API e integrações',
                'subtitle' => 'Automatize envios e receba eventos por webhooks.',
                'support_email' => (string) config('assinavelox.support_email'),
                // Conectores (G-CONN): há algum provedor de nuvem com app registrado?
                'cloud_import_available' => CloudImportFeature::enabled($organization)
                    && CloudFileSources::anyConfigured(),
            ]);
        }

        $this->authorizeManager();

        $tabs = IntegrationsNavigation::tabs($organization);
        $canOpenApi = $tabs['keys'] && Gate::allows('viewApiDocs');

        return Inertia::render('integrations/docs', [
            'navigation' => $tabs,
            'base_url' => url('/api/v1'),
            'openapi' => $canOpenApi ? ['ui_url' => url('/docs/api'), 'json_url' => url('/docs/api.json')] : null,
            'endpoints' => $tabs['keys'] ? ApiReference::endpoints($tabs['rest_hooks']) : [],
            'abilities' => ApiReference::abilities(),
            'problems' => ApiReference::problems(),
            'limits' => ApiReference::limits(),
            'events' => app(WebhookPresenter::class)->catalog(),
            'signature' => ApiReference::signature(),
            'sample_event' => app(RestHookSamples::class)->forEvent(WebhookEventType::RecipientSigned),
            // O `ConnectorsCallout` também aparece aqui: mesma regra do placeholder.
            'cloud_import_available' => CloudImportFeature::enabled($organization)
                && CloudFileSources::anyConfigured(),
        ]);
    }

    public function keys(): Response|RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        if (! ApiFeature::enabled($organization)) {
            return redirect()->route('integrations.index');
        }

        $membership = $this->authorizeManager();

        /** @var Organization $organization */
        return Inertia::render('integrations/keys', self::keysProps($organization, $membership));
    }

    public function logs(Request $request): Response|RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        if (! ApiFeature::enabled($organization)) {
            return redirect()->route('integrations.index');
        }

        $this->authorizeManager();
        /** @var Organization $organization */
        $filters = $request->validate([
            'token' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'string', Rule::in(['success', 'client_error', 'server_error'])],
            'period' => ['nullable', 'string', Rule::in(['24h', '7d', '30d'])],
        ]);

        $period = $filters['period'] ?? '7d';
        $since = match ($period) {
            '24h' => Carbon::now()->subDay(),
            '30d' => Carbon::now()->subDays(30),
            default => Carbon::now()->subDays(7),
        };

        // Já escopado à organização corrente (BelongsToOrganization).
        $base = ApiRequestLog::query()->where('occurred_at', '>=', $since);

        if (! empty($filters['token'])) {
            /** @var ApiToken|null $token */
            $token = ApiTokenManager::forOrganization($organization)->where('ulid', $filters['token'])->first();
            $base->where('personal_access_token_id', $token?->getKey() ?? 0);
        }

        $summary = [
            'total' => (clone $base)->count(),
            'success' => (clone $base)->where('status', '<', 400)->count(),
            'client_error' => (clone $base)->whereBetween('status', [400, 499])->count(),
            'server_error' => (clone $base)->where('status', '>=', 500)->count(),
        ];

        $query = (clone $base)->with('token')->latest('occurred_at')->latest('id');

        match ($filters['status'] ?? null) {
            'success' => $query->where('status', '<', 400),
            'client_error' => $query->whereBetween('status', [400, 499]),
            'server_error' => $query->where('status', '>=', 500),
            default => null,
        };

        $page = $query->paginate(50)->withQueryString();

        return Inertia::render('integrations/logs', [
            'navigation' => IntegrationsNavigation::tabs($organization),
            'logs' => self::paginated($page, static fn (ApiRequestLog $log): array => (new ApiRequestLogResource($log))->resolve(request())),
            'filters' => [
                'token' => $filters['token'] ?? null,
                'status' => $filters['status'] ?? null,
                'period' => $period,
            ],
            'summary' => $summary,
            'tokens' => ApiTokenManager::forOrganization($organization)->limit(200)->get(['id', 'ulid', 'name'])
                ->map(static fn (ApiToken $token): array => ['id' => $token->ulid, 'name' => $token->name])
                ->values()
                ->all(),
            'retention_days' => ApiRequestLog::retentionDays(),
        ]);
    }

    /**
     * Props da aba Chaves. `$revealed` só vem na resposta imediata da criação
     * (App\Http\Controllers\Integrations\KeysController::store): o texto do token nunca passa
     * pela sessão, pelo log ou por outra requisição.
     *
     * @param  array{id: string, name: string, token: string}|null  $revealed
     * @return array<string, mixed>
     */
    public static function keysProps(Organization $organization, Membership $membership, ?array $revealed = null): array
    {
        $tokens = ApiTokenManager::forOrganization($organization)->with('creator')->limit(200)->get();
        $counts = RestHookSubscriptions::countsByToken($organization, $tokens->map(static fn (ApiToken $token): int => (int) $token->getKey())->all());
        $grantable = ApiTokenManager::grantable($membership);

        return [
            'navigation' => IntegrationsNavigation::tabs($organization),
            'tokens' => $tokens->map(static fn (ApiToken $token): array => (new ApiTokenResource($token))->resolve(request()) + [
                'subscriptions' => $counts[(int) $token->getKey()] ?? 0,
            ])->values()->all(),
            'abilities' => array_map(static fn (ApiAbility $ability): array => [
                'value' => $ability->value,
                'label' => $ability->label(),
                'description' => $ability->description(),
                'grantable' => in_array($ability, $grantable, true),
            ], ApiAbility::cases()),
            'limits' => [
                'max_active' => ApiTokenManager::maxActivePerOrganization(),
                'active' => ApiTokenManager::forOrganization($organization)->usable()->count(),
                'max_expiration_days' => ApiTokenManager::maxExpirationDays(),
            ],
            'revealed_token' => $revealed,
        ];
    }

    /**
     * Formato Paginated<T> do front (resources/js/types/index.ts).
     *
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $paginator
     * @param  callable(TItem): array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private static function paginated(LengthAwarePaginator $paginator, callable $map): array
    {
        return app(WebhookPresenter::class)->paginated($paginator, $map);
    }

    private function authorizeManager(): Membership
    {
        $membership = CurrentOrganization::instance()->membership();

        abort_unless(IntegrationsNavigation::canManage($membership), 403, 'Só quem pode gerenciar a API e as integrações acessa esta área.');

        /** @var Membership $membership */
        return $membership;
    }
}
