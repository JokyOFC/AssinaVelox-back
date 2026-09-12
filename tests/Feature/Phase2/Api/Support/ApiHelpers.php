<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da API REST v1 (D-API, docs/fase-2/api-v1.md)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\ApiAbility;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Api\ApiTokenManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';

if (! function_exists('apiEnable')) {
    /**
     * Liga `api_integrations` na configuração global E no plano vigente da organização (o
     * plano free é compartilhado pelas organizações dos testes).
     */
    function apiEnable(Organization $organization): void
    {
        config()->set('assinavelox.features.api_integrations', true);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['api_integrations'] = true;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('apiMembership')) {
    function apiMembership(Organization $organization, User $user): Membership
    {
        return Membership::query()
            ->with(['organization', 'user'])
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail();
    }
}

if (! function_exists('apiIssueToken')) {
    /**
     * Emite pelo serviço real (exige `manage_integrations` e a flag). Devolve o TEXTO do token.
     *
     * @param  list<string>|null  $abilities
     */
    function apiIssueToken(Organization $organization, User $user, ?array $abilities = null, ?CarbonInterface $expiresAt = null): string
    {
        return app(ApiTokenManager::class)
            ->issue(apiMembership($organization, $user), 'Integração de teste', $abilities ?? ApiAbility::values(), $expiresAt)
            ->plainTextToken;
    }
}

if (! function_exists('apiRawToken')) {
    /**
     * Grava um token direto no banco, no formato do serviço — para cenários que o serviço
     * recusa de propósito (ex.: criador sem `manage_integrations`, token já vencido).
     *
     * @param  list<string>|null  $abilities
     * @param  array<string, mixed>  $attributes
     * @return array{0: string, 1: ApiToken}
     */
    function apiRawToken(Organization $organization, User $user, ?array $abilities = null, array $attributes = []): array
    {
        $secret = 'avk_'.Str::random(40);

        $token = new ApiToken;
        $token->forceFill(array_merge([
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $user->getKey(),
            'name' => 'Token direto',
            'token' => hash('sha256', $secret),
            'abilities' => $abilities ?? ApiAbility::values(),
            'token_prefix' => substr($secret, 0, 8),
        ], $attributes))->save();

        return [$token->getKey().'|'.$secret, $token];
    }
}

if (! function_exists('apiHeaders')) {
    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    function apiHeaders(string $token, array $extra = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'], $extra);
    }
}

if (! function_exists('apiIdem')) {
    /**
     * @return array{Idempotency-Key: string}
     */
    function apiIdem(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
    }
}

if (! function_exists('assertProblem')) {
    /**
     * Resposta RFC 9457 com a forma fixa da API v1 — e nada de diagnóstico interno.
     *
     * @return array<string, mixed>
     */
    function assertProblem(TestResponse $response, int $status, ?string $slug = null): array
    {
        $response->assertStatus($status);

        expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');

        /** @var array<string, mixed> $body */
        $body = $response->json();

        expect($body)->toHaveKeys(['type', 'title', 'status', 'instance', 'correlation_id'])
            ->and($body['status'])->toBe($status)
            ->and((string) $body['type'])->toStartWith('urn:assinavelox:problem:')
            ->and($body['correlation_id'])->toBe($response->headers->get('X-Correlation-Id'))
            ->and(array_intersect(array_keys($body), ['exception', 'file', 'line', 'trace', 'message']))->toBe([]);

        if ($slug !== null) {
            expect($body['type'])->toBe('urn:assinavelox:problem:'.$slug);
        }

        return $body;
    }
}

if (! function_exists('apiTwoOrganizations')) {
    /**
     * Duas organizações com owner e a API ligada.
     *
     * @return array{a: Organization, ownerA: User, b: Organization, ownerB: User}
     */
    function apiTwoOrganizations(): array
    {
        ['organization' => $a, 'owner' => $ownerA] = createOrganizationWithOwner(['name' => 'Imobiliária Horizonte']);
        ['organization' => $b, 'owner' => $ownerB] = createOrganizationWithOwner(['name' => 'Construtora Aurora']);

        apiEnable($a);
        apiEnable($b);

        return ['a' => $a, 'ownerA' => $ownerA, 'b' => $b, 'ownerB' => $ownerB];
    }
}
