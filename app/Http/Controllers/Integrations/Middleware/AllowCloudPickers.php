<?php

namespace App\Http\Controllers\Integrations\Middleware;

use App\Services\CloudImport\CloudImportCsp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca a resposta da PÁGINA de importação para o SecurityHeaders acrescentar os hosts do
 * Google Picker e do Dropbox Chooser na CSP (App\Services\CloudImport\CloudImportCsp). Só
 * essa página; o resto do app continua com a política da Fase 1.
 */
class AllowCloudPickers
{
    public function handle(Request $request, Closure $next): Response
    {
        CloudImportCsp::allow($request);

        return $next($request);
    }
}
