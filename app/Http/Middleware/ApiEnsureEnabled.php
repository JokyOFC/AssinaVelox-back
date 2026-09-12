<?php

namespace App\Http\Middleware;

use App\Services\Api\ApiFeature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * API v1 com o interruptor global `assinavelox.features.api_integrations` desligado (o padrão):
 * 404 em qualquer rota, antes de olhar credencial — para quem não tem o recurso, ele não existe.
 */
class ApiEnsureEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ApiFeature::globallyEnabled()) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
