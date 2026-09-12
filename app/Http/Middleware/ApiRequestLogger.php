<?php

namespace App\Http\Middleware;

use App\Services\Api\ApiContext;
use App\Services\Api\ApiRequestRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primeiro middleware do grupo `api`: mede a requisição inteira (inclusive 401/403/404/429
 * gerados pelos middlewares seguintes) e, se um token foi autenticado, grava o registro mínimo
 * em `api_request_logs` (App\Services\Api\ApiRequestRecorder). Sem token, nada é gravado:
 * sem organização não há a quem mostrar o registro.
 */
class ApiRequestLogger
{
    public function __construct(private readonly ApiRequestRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        $response = $next($request);

        $token = ApiContext::token($request);

        if ($token !== null) {
            $this->recorder->record($request, $response, $token, $startedAt);
        }

        return $response;
    }
}
