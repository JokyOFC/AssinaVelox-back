<?php

namespace App\Http\Controllers\Integrations\Middleware;

use App\Services\Webhooks\WebhooksFeature;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestão de webhooks (D-HOOK): 404 com a flag `outbound_webhooks` desligada para a organização
 * corrente. Roda como middleware de controller, depois do `org` (que define a organização).
 *
 * Classe (e não closure) de propósito: quem inspeciona o middleware das rotas — o smoke test
 * de rotas GET, `Permissions::requiredForRoute` — espera nomes, não closures.
 */
class EnsureOutboundWebhooksFeature
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(WebhooksFeature::enabled(CurrentOrganization::instance()->get()), 404);

        return $next($request);
    }
}
