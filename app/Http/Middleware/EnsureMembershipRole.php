<?php

namespace App\Http\Middleware;

use App\Enums\MembershipRole;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que o papel do usuário na organização corrente esteja entre os informados
 * (alias `org.role`, ex.: `org.role:owner,admin`). Deve vir depois de `org`. → 403.
 */
class EnsureMembershipRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $membership = CurrentOrganization::instance()->membership();

        if ($membership === null) {
            abort(403, 'Nenhuma organização ativa.');
        }

        $allowed = array_values(array_filter(array_map(
            fn (string $role): ?MembershipRole => MembershipRole::tryFrom(trim($role)),
            $roles,
        )));

        // Fase 2 §2.14: para papéis de sistema o resultado é idêntico ao da Fase 1 (o papel
        // precisa estar na lista); uma função personalizada passa se tiver a permissão
        // equivalente à rota (Permissions::ROUTE_PERMISSIONS).
        if ($allowed === [] || ! Permissions::routeAllows($membership, $allowed, $request->route()?->getName())) {
            abort(403, 'Você não tem permissão para acessar este recurso.');
        }

        return $next($request);
    }
}
