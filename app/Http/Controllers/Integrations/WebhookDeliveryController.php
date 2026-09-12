<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integrations\Middleware\EnsureOutboundWebhooksFeature;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookActionRefused;
use App\Services\Webhooks\WebhookEndpointManager;
use App\Services\Webhooks\WebhookPresenter;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;

/**
 * Histórico de uma entrega (JSON para a gaveta de detalhes) e reenvio manual. A entrega é
 * resolvida DENTRO do endpoint (scopeBindings) e o endpoint dentro da organização corrente.
 */
class WebhookDeliveryController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly WebhookEndpointManager $manager,
        private readonly WebhookPresenter $presenter,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware(EnsureOutboundWebhooksFeature::class)];
    }

    public function show(WebhookEndpoint $webhookEndpoint, WebhookDelivery $delivery): JsonResponse
    {
        Gate::authorize('view', $webhookEndpoint);

        return response()
            ->json($this->presenter->deliveryDetail($delivery, CurrentOrganization::instance()->membership()))
            ->header('Cache-Control', 'no-store, private');
    }

    public function resend(WebhookEndpoint $webhookEndpoint, WebhookDelivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $webhookEndpoint);

        try {
            $this->manager->resend($delivery);
        } catch (WebhookActionRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return back()->with('success', 'Reenvio enfileirado com o mesmo id de entrega ('.$delivery->ulid.').');
    }
}
