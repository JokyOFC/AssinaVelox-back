<?php

namespace App\Http\Controllers\PublicForms\Middleware;

use App\Services\PublicForms\PublicFormsFeature;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telas internas do formulário público: com a flag `public_forms` desligada para a
 * organização corrente, 404 ANTES de FormRequest e Policy (o recurso não existe para ela).
 */
class EnsurePublicFormsFeature
{
    public function handle(Request $request, Closure $next): Response
    {
        PublicFormsFeature::ensure(CurrentOrganization::instance()->get());

        return $next($request);
    }
}
