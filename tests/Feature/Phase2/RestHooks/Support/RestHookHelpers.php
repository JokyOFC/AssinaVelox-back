<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de REST Hooks e das telas de integrações (D-PLAT)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. Nenhum helper acessa a rede: o DNS é o
| FakeDnsResolver do D-HOOK e as chamadas HTTP de verdade ficam proibidas.
*/

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Api/Support/ApiHelpers.php';
require_once __DIR__.'/../../Webhooks/Support/WebhookHelpers.php';

if (! function_exists('restHooksEnable')) {
    /**
     * Liga `api_integrations`, `outbound_webhooks` e `rest_hooks` (global E plano).
     */
    function restHooksEnable(Organization $organization, bool $restHooks = true, bool $webhooks = true): void
    {
        apiEnable($organization);
        webhooksOn($organization, $webhooks);

        config()->set('assinavelox.features.rest_hooks', $restHooks);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['rest_hooks'] = $restHooks;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('restHooksOrg')) {
    /**
     * Organização com as três flags ligadas, DNS falso e rede proibida.
     *
     * @param  array<string, list<string>>  $dns
     * @return array{organization: Organization, owner: User}
     */
    function restHooksOrg(array $dns = [], array $attributes = []): array
    {
        $context = createOrganizationWithOwner($attributes);
        restHooksEnable($context['organization']);
        fakeDns($dns);
        Http::preventStrayRequests();

        return ['organization' => $context['organization'], 'owner' => $context['owner']];
    }
}

if (! function_exists('restHookToken')) {
    /**
     * Emite um token pelo serviço real e devolve [texto, modelo].
     *
     * @param  list<string>|null  $abilities
     * @return array{0: string, 1: ApiToken}
     */
    function restHookToken(Organization $organization, User $user, ?array $abilities = null): array
    {
        $plain = apiIssueToken($organization, $user, $abilities ?? ['webhooks:manage', 'envelopes:read']);
        $id = (int) explode('|', $plain, 2)[0];

        /** @var ApiToken $token */
        $token = ApiToken::withoutOrganizationScope()->findOrFail($id);

        return [$plain, $token];
    }
}

if (! function_exists('restSubscribe')) {
    /**
     * @param  array<string, mixed>  $body
     */
    function restSubscribe(string $token, array $body, ?string $key = null): TestResponse
    {
        return test()->postJson('/api/v1/webhook-subscriptions', $body, apiHeaders($token, apiIdem($key)));
    }
}

if (! function_exists('inertiaPost')) {
    /**
     * POST como visita Inertia (resposta JSON com component/props).
     *
     * @param  array<string, mixed>  $data
     */
    function inertiaPost(string $uri, array $data = []): TestResponse
    {
        $version = (string) (app(HandleInertiaRequests::class)->version(request()) ?? '');

        return test()->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->post($uri, $data);
    }
}

if (! function_exists('payloadShape')) {
    /**
     * Forma de um payload: as chaves (ordenadas), recursivamente; folhas viram null.
     *
     * @return array<string, mixed>|null
     */
    function payloadShape(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        ksort($value);

        return array_map(static fn (mixed $child): ?array => payloadShape($child), $value);
    }
}
