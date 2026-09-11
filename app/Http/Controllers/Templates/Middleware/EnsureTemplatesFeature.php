<?php

namespace App\Http\Controllers\Templates\Middleware;

use App\Services\Templates\TemplatesFeature;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Com a flag `templates` desligada para a organização corrente, a rota de modelos responde
 * 404 ANTES de qualquer FormRequest ou Policy (o recurso não existe para a organização).
 * Aplicado pelos próprios controllers de modelos (HasMiddleware), depois do `org` do grupo.
 */
class EnsureTemplatesFeature
{
    public function handle(Request $request, Closure $next): Response
    {
        TemplatesFeature::ensure(CurrentOrganization::instance()->get());

        return $next($request);
    }
}
