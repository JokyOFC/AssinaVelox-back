<?php

namespace App\Http\Controllers\BulkGenerations\Middleware;

use App\Services\BulkGeneration\BulkGenerationFeature;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flag `bulk_generation` desligada para a organização corrente: 404 antes de FormRequest e
 * autorização (o recurso não existe para ela). Referenciado pela CLASSE (o teste de fumaça de
 * rotas GET lê a lista de middleware como strings).
 */
class EnsureBulkGenerationFeature
{
    public function handle(Request $request, Closure $next): Response
    {
        BulkGenerationFeature::ensure(CurrentOrganization::instance()->get());

        return $next($request);
    }
}
