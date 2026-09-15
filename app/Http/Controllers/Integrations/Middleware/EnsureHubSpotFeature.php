<?php

namespace App\Http\Controllers\Integrations\Middleware;

use App\Services\HubSpot\HubSpotFeature;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App HubSpot (G-CONN): 404 com a flag `hubspot` desligada para a organização corrente.
 */
class EnsureHubSpotFeature
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(HubSpotFeature::enabled(CurrentOrganization::instance()->get()), 404);

        return $next($request);
    }
}
