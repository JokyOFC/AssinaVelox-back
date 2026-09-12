<?php

namespace App\Services\Api;

use App\Enums\ApiAbility;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Contexto da requisição da API v1: o token autenticado (atributo da requisição) e a
 * organização/membership do CRIADOR do token (App\Support\CurrentOrganization, definida por
 * App\Http\Middleware\ApiAuthenticate). Nada aqui vem da sessão.
 */
final class ApiContext
{
    public const TOKEN_ATTRIBUTE = 'assinavelox.api_token';

    public static function set(Request $request, ApiToken $token): void
    {
        $request->attributes->set(self::TOKEN_ATTRIBUTE, $token);
    }

    public static function token(?Request $request = null): ?ApiToken
    {
        $token = ($request ?? request())->attributes->get(self::TOKEN_ATTRIBUTE);

        return $token instanceof ApiToken ? $token : null;
    }

    /**
     * O token da requisição tem a ability? Fora da API (sem token) responde false — quem chama
     * decide o que isso significa (nos recursos da v1: omitir o dado protegido pela ability).
     */
    public static function allows(ApiAbility $ability, ?Request $request = null): bool
    {
        return self::token($request)?->can($ability) ?? false;
    }

    /**
     * @throws AuthenticationException
     */
    public static function membership(): Membership
    {
        return CurrentOrganization::instance()->membership() ?? throw new AuthenticationException;
    }

    /**
     * @throws AuthenticationException
     */
    public static function organization(): Organization
    {
        return CurrentOrganization::instance()->get() ?? throw new AuthenticationException;
    }
}
