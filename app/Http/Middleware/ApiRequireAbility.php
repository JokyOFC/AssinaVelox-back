<?php

namespace App\Http\Middleware;

use App\Enums\ApiAbility;
use App\Services\Api\ApiContext;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.ability:envelopes:read[,…]` — a rota exige a(s) ability(ies) no token (403
 * `missing-ability`) E que o CRIADOR ainda tenha, agora, as permissões que a ability exige
 * (403 `creator-lacks-permission`). Depois disso as Policies decidem sobre o recurso.
 */
class ApiRequireAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = ApiContext::token($request) ?? throw new AuthenticationException('Unauthenticated.');
        $membership = CurrentOrganization::instance()->membership() ?? throw new AuthenticationException('Unauthenticated.');

        foreach ($abilities as $value) {
            $ability = ApiAbility::tryFrom($value) ?? throw new LogicException("Ability de API desconhecida na rota: {$value}");

            if (! $token->can($ability)) {
                throw ApiProblemException::missingAbility($ability);
            }

            if (! Permissions::covers($membership, $ability->requiredPermissions())) {
                throw ApiProblemException::creatorLacksPermission($ability);
            }
        }

        return $next($request);
    }
}
