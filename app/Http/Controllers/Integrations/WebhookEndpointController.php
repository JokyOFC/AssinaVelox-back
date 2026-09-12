<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integrations\Middleware\EnsureOutboundWebhooksFeature;
use App\Http\Requests\Integrations\RotateWebhookSecretRequest;
use App\Http\Requests\Integrations\StoreWebhookEndpointRequest;
use App\Http\Requests\Integrations\UpdateWebhookEndpointRequest;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookActionRefused;
use App\Services\Webhooks\WebhookEndpointManager;
use App\Services\Webhooks\WebhookPresenter;
use App\Support\CurrentOrganization;
use App\Support\Http\BlockedOutboundUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestão dos endpoints de webhook pela interface web (roadmap §2.16; docs/fase-2/webhooks.md
 * §9). Flag `outbound_webhooks` desligada: 404 em todas as rotas. Autorização:
 * WebhookEndpointPolicy (`manage_integrations`).
 *
 * Páginas (a cargo do front): `integrations/webhooks/index` e `integrations/webhooks/show`.
 * O segredo em claro chega UMA vez, na prop `revealed_secret`, logo depois de criar ou rotacionar.
 */
class WebhookEndpointController extends Controller implements HasMiddleware
{
    private const SECRET_FLASH = 'webhook_secret';

    public function __construct(
        private readonly WebhookEndpointManager $manager,
        private readonly WebhookPresenter $presenter,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware(EnsureOutboundWebhooksFeature::class)];
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WebhookEndpoint::class);

        $endpoints = WebhookEndpoint::query()->with('creator')->latest('id')->get();

        return Inertia::render('integrations/webhooks/index', [
            'endpoints' => $endpoints->map(fn (WebhookEndpoint $endpoint): array => $this->presenter->endpoint($endpoint))->values()->all(),
            'catalog' => $this->presenter->catalog(),
            'limits' => $this->presenter->limits(),
            'revealed_secret' => $this->revealedSecret($request),
        ]);
    }

    public function store(StoreWebhookEndpointRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        abort_if($organization === null, 404);

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->manager->create(
                $organization,
                $user,
                (string) $request->validated('url'),
                array_values((array) $request->validated('events')),
                $request->validated('description'),
            );
        } catch (BlockedOutboundUrl $blocked) {
            return back()->withErrors(['url' => $blocked->userMessage()])->withInput();
        } catch (WebhookActionRefused $refused) {
            return back()->withErrors(['url' => $refused->getMessage()])->withInput();
        }

        $endpoint = $result['endpoint'];

        return redirect()
            ->route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid])
            ->with(self::SECRET_FLASH, ['endpoint' => $endpoint->ulid, 'secret' => $result['secret']])
            ->with('success', 'Endpoint criado. Copie o segredo agora: ele não será mostrado de novo.');
    }

    public function show(Request $request, WebhookEndpoint $webhookEndpoint): Response
    {
        Gate::authorize('view', $webhookEndpoint);

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(WebhookDelivery::STATUSES)],
            'event' => ['nullable', 'string', 'max:60'],
        ]);

        $query = $webhookEndpoint->deliveries()
            ->with(['envelope' => fn ($relation) => $relation->withoutGlobalScopes()])
            ->latest('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['event'])) {
            $query->where('event_type', $filters['event']);
        }

        $viewer = CurrentOrganization::instance()->membership();
        $page = $query->paginate(25)->withQueryString();

        return Inertia::render('integrations/webhooks/show', [
            'endpoint' => $this->presenter->endpoint($webhookEndpoint->loadMissing('creator')),
            'deliveries' => $this->presenter->paginated(
                $page,
                fn (WebhookDelivery $delivery): array => $this->presenter->delivery($delivery, $viewer),
            ),
            'filters' => ['status' => $filters['status'] ?? null, 'event' => $filters['event'] ?? null],
            'catalog' => $this->presenter->catalog(),
            'statuses' => $this->presenter->statuses(),
            'limits' => $this->presenter->limits(),
            'revealed_secret' => $this->revealedSecret($request, $webhookEndpoint),
        ]);
    }

    public function update(UpdateWebhookEndpointRequest $request, WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        try {
            /** @var array{url?: string, events?: list<string>, description?: string|null} $data */
            $data = $request->validated();
            $this->manager->update($webhookEndpoint, $data);
        } catch (BlockedOutboundUrl $blocked) {
            return back()->withErrors(['url' => $blocked->userMessage()])->withInput();
        } catch (WebhookActionRefused $refused) {
            return back()->withErrors(['events' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', 'Endpoint atualizado.');
    }

    public function pause(WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        Gate::authorize('update', $webhookEndpoint);

        $this->manager->pause($webhookEndpoint);

        return back()->with('success', 'Endpoint pausado. Nenhum evento é entregue até ele ser reativado.');
    }

    public function resume(Request $request, WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        Gate::authorize('update', $webhookEndpoint);

        /** @var User $user */
        $user = $request->user();

        try {
            $this->manager->resume($webhookEndpoint, $user);
        } catch (BlockedOutboundUrl $blocked) {
            return back()->with('error', $blocked->userMessage());
        }

        return back()->with('success', 'Endpoint reativado. As entregas que falharam podem ser reenviadas pelo histórico.');
    }

    public function rotateSecret(RotateWebhookSecretRequest $request, WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        $overlap = $request->validated('overlap_hours');
        $secret = $this->manager->rotateSecret($webhookEndpoint, $overlap === null ? null : (int) $overlap);
        $webhookEndpoint->refresh();

        $message = $webhookEndpoint->previousSecretActive()
            ? 'Segredo novo gerado. O anterior continua válido até '
                .$webhookEndpoint->previous_secret_expires_at?->timezone(CurrentOrganization::instance()->get()->timezone ?? 'America/Sao_Paulo')->format('d/m/Y H:i')
                .'. Copie o novo agora: ele não será mostrado de novo.'
            : 'Segredo novo gerado e o anterior encerrado. Copie o novo agora: ele não será mostrado de novo.';

        return redirect()
            ->route('integrations.webhooks.show', ['webhookEndpoint' => $webhookEndpoint->ulid])
            ->with(self::SECRET_FLASH, ['endpoint' => $webhookEndpoint->ulid, 'secret' => $secret])
            ->with('success', $message);
    }

    public function expirePreviousSecret(WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        Gate::authorize('update', $webhookEndpoint);

        $this->manager->expirePreviousSecret($webhookEndpoint);

        return back()->with('success', 'O segredo anterior deixou de valer. Só o atual assina as entregas.');
    }

    public function test(WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        Gate::authorize('update', $webhookEndpoint);

        $this->manager->sendTest($webhookEndpoint);

        return back()->with('success', 'Evento de teste enfileirado. Acompanhe o resultado no histórico.');
    }

    public function destroy(WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        Gate::authorize('delete', $webhookEndpoint);

        $this->manager->delete($webhookEndpoint);

        return redirect()->route('integrations.webhooks.index')->with('success', 'Endpoint removido. Nenhum evento novo será enviado a ele.');
    }

    /**
     * Segredo recém-gerado (flash de UMA requisição). Só aparece na página do endpoint a que
     * pertence; recarregar a página já não o traz.
     *
     * @return array{endpoint: string, secret: string}|null
     */
    private function revealedSecret(Request $request, ?WebhookEndpoint $endpoint = null): ?array
    {
        $flash = $request->session()->get(self::SECRET_FLASH);

        if (! is_array($flash) || ! is_string($flash['endpoint'] ?? null) || ! is_string($flash['secret'] ?? null)) {
            return null;
        }

        if ($endpoint !== null && $flash['endpoint'] !== $endpoint->ulid) {
            return null;
        }

        return ['endpoint' => $flash['endpoint'], 'secret' => $flash['secret']];
    }
}
