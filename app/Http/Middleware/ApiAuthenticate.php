<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Api\ApiContext;
use App\Services\Api\ApiFeature;
use App\Services\Api\ApiRateLimits;
use App\Support\CurrentOrganization;
use App\Support\OrganizationSettings;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Autenticação da API v1 por token (`Authorization: Bearer {id}|{segredo}`) — sem sessão,
 * sem cookie, sem CSRF.
 *
 * 1. O token é procurado pelo padrão do Sanctum (id + `hash_equals` sobre o SHA-256); ausente,
 *    incorreto, revogado ou expirado → 401 (a resposta não diz qual).
 * 2. O token age como o usuário que o CRIOU, na organização DO TOKEN: exige membership ATIVA
 *    desse usuário nessa organização, organização existente, conta não bloqueada, e-mail
 *    verificado e — se a organização exige 2FA — TOTP confirmado (as mesmas barreiras
 *    `verified` e `org.2fa` da interface). Perdeu o acesso → 401 (o token deixa de valer junto).
 * 3. Flag `api_integrations` desligada no plano da organização → 404.
 * 4. Define App\Support\CurrentOrganization (escopo global, binding por ULID escopado → 404
 *    para recurso de outra organização) e o usuário do guard padrão (as Policies decidem com
 *    as permissões ATUAIS do criador).
 *
 * Falhas de autenticação contam num balde por IP; estourado, 429 antes de consultar o banco.
 */
class ApiAuthenticate
{
    private const LAST_USED_WRITE_INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $failureKey = 'api-auth-failure|'.$request->ip();
        $maxFailures = ApiRateLimits::failedAuthPerMinute();

        if (RateLimiter::tooManyAttempts($failureKey, $maxFailures)) {
            $retryAfter = RateLimiter::availableIn($failureKey);

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $retryAfter,
                'RateLimit-Limit' => $maxFailures,
                'RateLimit-Remaining' => 0,
                'RateLimit-Reset' => $retryAfter,
            ]);
        }

        [$token, $user, $organization, $membership] = $this->resolve($request->bearerToken());

        if ($token === null || $user === null || $organization === null || $membership === null) {
            RateLimiter::hit($failureKey, 60);

            throw new AuthenticationException('Unauthenticated.');
        }

        if (! ApiFeature::enabled($organization)) {
            throw new NotFoundHttpException;
        }

        $membership->setRelation('organization', $organization);
        $membership->setRelation('user', $user);

        CurrentOrganization::instance()->set($organization, $membership);
        ApiContext::set($request, $token);

        Auth::guard()->setUser($user);
        $request->setUserResolver(static fn (): User => $user);

        $this->touch($token, $request);

        try {
            return $next($request);
        } finally {
            // Nada da identidade do token sobrevive à requisição (testes, Octane, filas `sync`).
            Auth::guard()->forgetUser();
        }
    }

    /**
     * @return array{0: ApiToken|null, 1: User|null, 2: Organization|null, 3: Membership|null}
     */
    private function resolve(?string $plain): array
    {
        $none = [null, null, null, null];

        if (! is_string($plain) || $plain === '' || strlen($plain) > 255) {
            return $none;
        }

        $token = ApiToken::findToken($plain);

        if (! $token instanceof ApiToken || ! $token->isUsable()) {
            return $none;
        }

        $user = User::query()->find($token->created_by_user_id);
        $organization = Organization::query()->find($token->organization_id);

        if (! $user instanceof User || ! $organization instanceof Organization) {
            return $none;
        }

        // Mesmo tokenable e mesmo criador: um token nunca age por outra pessoa.
        if ((int) $token->tokenable_id !== (int) $user->getKey() || $token->tokenable_type !== $user->getMorphClass()) {
            return $none;
        }

        // Conta bloqueada pela equipe da plataforma: nenhuma credencial dela vale.
        if ($user->getAttribute('blocked_at') !== null) {
            return $none;
        }

        // E-mail não verificado (ex.: trocado e ainda não confirmado): a interface inteira exige
        // `verified`; o token não faz mais do que a pessoa pode fazer agora.
        if (! $user->hasVerifiedEmail()) {
            return $none;
        }

        $membership = Membership::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organization->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->first();

        if (! $membership instanceof Membership) {
            return $none;
        }

        // Política "Exigir autenticação em duas etapas" (a mesma do `org.2fa` da interface):
        // criador sem TOTP confirmado não usa a organização — nem pela interface, nem pelo token.
        if (OrganizationSettings::of($organization)->requireTwoFactor() && ! $user->hasTwoFactorEnabled()) {
            return $none;
        }

        return [$token, $user, $organization, $membership];
    }

    /**
     * `last_used_at`/`last_used_ip` no máximo uma vez por minuto (sem uma escrita por chamada).
     */
    private function touch(ApiToken $token, Request $request): void
    {
        $last = $token->last_used_at;

        if ($last !== null && $last->diffInSeconds(Carbon::now(), true) < self::LAST_USED_WRITE_INTERVAL_SECONDS) {
            return;
        }

        DB::table('personal_access_tokens')->where('id', $token->getKey())->update([
            'last_used_at' => Carbon::now(),
            'last_used_ip' => mb_substr((string) $request->ip(), 0, 45),
        ]);
    }
}
