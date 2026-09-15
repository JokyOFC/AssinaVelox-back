<?php

namespace App\Http\Controllers\Integrations\Middleware;

use App\Services\CloudImport\CloudImportFeature;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Importação da nuvem (G-CONN): 404 com a flag `cloud_import` desligada para a organização
 * corrente. Classe (não closure): o smoke test de rotas e `Permissions::requiredForRoute`
 * inspecionam o middleware pelo nome.
 */
class EnsureCloudImportFeature
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(CloudImportFeature::enabled(CurrentOrganization::instance()->get()), 404);

        return $next($request);
    }
}
