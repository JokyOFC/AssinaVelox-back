<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que nenhuma organização "vaze" entre requisições (testes, Octane, filas síncronas):
 * o singleton CurrentOrganization começa vazio em toda requisição e só o middleware `org`
 * o preenche. Rotas fora de `org` (admin, criar organização, público) nunca têm organização.
 */
class ResetCurrentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        CurrentOrganization::instance()->clear();

        try {
            return $next($request);
        } finally {
            CurrentOrganization::instance()->clear();
        }
    }
}
