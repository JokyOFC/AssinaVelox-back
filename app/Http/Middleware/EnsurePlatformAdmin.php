<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Painel interno (alias `platform-admin`): exige `users.is_platform_admin`. → 403.
 * As rotas admin NÃO passam por `org`: consultas usam withoutOrganizationScope()/forOrganization().
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_platform_admin) {
            abort(403, 'Acesso restrito à equipe AssinaVelox.');
        }

        return $next($request);
    }
}
