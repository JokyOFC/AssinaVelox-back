<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acesso à documentação OpenAPI (`/docs/api` e `/docs/api.json`) pelo gate `viewApiDocs`
 * (App\Services\Api\ApiDocumentation). Substitui o RestrictedDocsAccess do Scramble, que
 * libera tudo no ambiente `local`: aqui a regra é a mesma em qualquer ambiente.
 */
class ApiDocsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::allows('viewApiDocs'), 403);

        return $next($request);
    }
}
