<?php

namespace App\Http\Middleware;

use App\Enums\MembershipRole;
use App\Support\CurrentOrganization;
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
        $current = CurrentOrganization::instance()->role();

        if ($current === null) {
            abort(403, 'Nenhuma organização ativa.');
        }

        $allowed = array_filter(array_map(
            fn (string $role): ?MembershipRole => MembershipRole::tryFrom(trim($role)),
            $roles,
        ));

        if ($allowed === [] || ! in_array($current, $allowed, true)) {
            abort(403, 'Você não tem permissão para acessar este recurso.');
        }

        return $next($request);
    }
}
