<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integrations\Middleware\EnsureHubSpotFeature;
use App\Integrations\HubSpot\HubSpotClient;
use App\Models\HubSpotActionExecution;
use App\Models\HubSpotConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use App\Services\CloudImport\Http\ConnectorFailure;
use App\Services\CloudImport\OAuth\CallbackQuery;
use App\Services\CloudImport\OAuth\OAuthStateStore;
use App\Services\HubSpot\HubSpotConnections;
use App\Services\HubSpot\HubSpotException;
use App\Services\HubSpot\HubSpotExecutionPresenter;
use App\Services\RestHooks\IntegrationsNavigation;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Integrações → HubSpot (Fase 3 §3.9, G-CONN, docs/fase-3/conectores.md §5 e §8).
 *
 * Flag `hubspot` desligada: 404. Tela, conexão e desconexão exigem `manage_integrations`
 * (a mesma permissão das chaves e dos webhooks). Estado honesto: sem o app de desenvolvedor
 * registrado pelo proprietário, "aguardando app registrado pelo proprietário" e o botão de
 * conectar desabilitado — nada finge uma conexão.
 */
final class HubSpotController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly HubSpotClient $client,
        private readonly HubSpotConnections $connections,
        private readonly OAuthStateStore $states,
    ) {}

    public static function middleware(): array
    {
        return [EnsureHubSpotFeature::class];
    }

    public function show(): Response
    {
        $this->authorizeManager();
        /** @var Organization $organization */
        $organization = CurrentOrganization::instance()->get();
        $connection = HubSpotConnections::forOrganization($organization);

        $status = match (true) {
            ! $this->client->isConfigured() => 'awaiting_app',
            $connection === null => 'disconnected',
            $connection->status === HubSpotConnection::STATUS_ERROR => 'error',
            default => 'connected',
        };

        return Inertia::render('integrations/hubspot', [
            'status' => $status,
            'missing' => $this->client->missingConfiguration(),
            'connection' => $connection === null ? null : [
                'id' => $connection->ulid,
                'portal_id' => $connection->portal_id,
                'connected_at' => $connection->connected_at?->toIso8601String(),
                'connected_by' => $connection->connectedBy?->name,
                'scopes' => $connection->scopes ?? [],
                'last_error' => $connection->last_error_code,
            ],
            'action' => [
                'url' => route('webhooks.hubspot.action'),
                'status_property' => (string) config('assinavelox.hubspot.status_property'),
                'max_participants' => (int) config('assinavelox.hubspot.max_participants', 5),
            ],
            'templates' => Template::query()
                ->whereNotNull('current_version_id')
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'ulid', 'name', 'status', 'current_version_id'])
                ->filter(static fn (Template $template): bool => $template->isUsable())
                ->map(static fn (Template $template): array => ['id' => $template->ulid, 'name' => $template->name])
                ->values()
                ->all(),
            'executions' => HubSpotActionExecution::query()
                ->with('envelope:id,ulid,title')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(static fn (HubSpotActionExecution $execution): array => HubSpotExecutionPresenter::row($execution))
                ->values()
                ->all(),
        ]);
    }

    public function connect(): SymfonyResponse
    {
        $membership = $this->authorizeManager();

        if (! $this->client->isConfigured()) {
            return back()->with('error', 'Conexão com o HubSpot ainda não disponível: aguardando app registrado pelo proprietário.');
        }

        $pending = $this->states->issue('hubspot', [
            'organization' => (int) $membership->organization_id,
            'user' => (int) $membership->user_id,
        ], pkce: false);

        return Inertia::location($this->client->authorizationUrl($pending->state, route('integrations.hubspot.callback')));
    }

    public function callback(Request $request): RedirectResponse
    {
        $membership = $this->authorizeManager();
        $query = CallbackQuery::take($request, ['state', 'code', 'error']);
        $consumed = $this->states->consume('hubspot', $query['state']);
        $back = redirect()->route('integrations.hubspot.show');

        if ($consumed === null
            || (int) $consumed->contextValue('organization') !== (int) $membership->organization_id
            || (int) $consumed->contextValue('user') !== (int) $membership->user_id) {
            return $back->with('error', 'A autorização do HubSpot expirou ou já foi usada. Conecte de novo.');
        }

        if ($query['error'] !== null) {
            return $back->with('error', 'A conexão foi cancelada no HubSpot.');
        }

        $code = $query['code'];

        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            return $back->with('error', 'Resposta inválida do HubSpot. Conecte de novo.');
        }

        /** @var User $user */
        $user = $request->user();
        /** @var Organization $organization */
        $organization = CurrentOrganization::instance()->get();

        try {
            $tokens = $this->client->exchangeCode($code, route('integrations.hubspot.callback'));
            $this->connections->connect($organization, $user, $tokens);
        } catch (HubSpotException $exception) {
            return $back->with('error', $exception->userMessage());
        } catch (ConnectorFailure) {
            return $back->with('error', 'Não foi possível concluir a conexão com o HubSpot. Tente de novo em instantes.');
        }

        return $back->with('success', 'Conta do HubSpot conectada.');
    }

    public function disconnect(): RedirectResponse
    {
        $this->authorizeManager();
        /** @var Organization $organization */
        $organization = CurrentOrganization::instance()->get();
        $connection = HubSpotConnections::forOrganization($organization);

        if ($connection === null) {
            return back()->with('info', 'Nenhuma conta do HubSpot conectada.');
        }

        $this->connections->disconnect($connection);

        return back()->with('success', 'Conta do HubSpot desconectada. O acesso foi revogado e as chaves apagadas.');
    }

    private function authorizeManager(): Membership
    {
        $membership = CurrentOrganization::instance()->membership();

        abort_unless(IntegrationsNavigation::canManage($membership), 403, 'Só quem pode gerenciar a API e as integrações acessa esta área.');

        /** @var Membership $membership */
        return $membership;
    }
}
