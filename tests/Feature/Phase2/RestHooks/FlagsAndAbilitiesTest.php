<?php

use App\Enums\MembershipRole;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/Support/RestHookHelpers.php';

/*
|--------------------------------------------------------------------------
| REST Hooks — flags (roadmap T8) e abilities
|--------------------------------------------------------------------------
| `rest_hooks` nasce desligada e só vale com `api_integrations` E `outbound_webhooks`. Toda
| rota exige a ability `webhooks:manage` e que o criador do token ainda tenha
| `manage_integrations`.
*/

/**
 * @return list<array{0: string, 1: string}> [método, url]
 */
function restHookRoutes(): array
{
    return [
        ['GET', '/api/v1/webhook-events'],
        ['GET', '/api/v1/webhook-events/envelope.sent/sample'],
        ['GET', '/api/v1/webhook-subscriptions'],
        ['POST', '/api/v1/webhook-subscriptions'],
        ['DELETE', '/api/v1/webhook-subscriptions/01HZZZZZZZZZZZZZZZZZZZZZZZ'],
    ];
}

test('a flag nasce desligada', function (): void {
    expect(config('assinavelox.features.rest_hooks'))->toBeFalse();
});

test('as rotas de REST Hooks declaram webhooks:manage e só a criação exige Idempotency-Key', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'api.v1.webhook_'));

    expect($routes)->toHaveCount(5);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('api.ability:webhooks:manage');
        expect(in_array('api.idempotent', $middleware, true))->toBe($route->getName() === 'api.v1.webhook_subscriptions.store');
    }
});

test('flag rest_hooks desligada (API e webhooks ligados): 404 em todas as rotas e nada é criado', function (): void {
    ['organization' => $organization, 'owner' => $owner] = restHooksOrg();
    restHooksEnable($organization, false);
    [$plain] = restHookToken($organization, $owner);

    foreach (restHookRoutes() as [$method, $url]) {
        assertProblem($this->json($method, $url, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'], apiHeaders($plain, apiIdem())), 404, 'not-found');
    }

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(0);
});

test('com rest_hooks ligada mas webhooks de saída desligados: 404 (nada seria entregue)', function (): void {
    ['organization' => $organization, 'owner' => $owner] = restHooksOrg();
    restHooksEnable($organization, true, false);
    [$plain] = restHookToken($organization, $owner);

    foreach (restHookRoutes() as [$method, $url]) {
        assertProblem($this->json($method, $url, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'], apiHeaders($plain, apiIdem())), 404, 'not-found');
    }
});

test('só o plano sem a flag (interruptor global ligado): 404', function (): void {
    ['organization' => $organization, 'owner' => $owner] = restHooksOrg();
    [$plain] = restHookToken($organization, $owner);

    $plan = $organization->currentSubscription()->with('plan')->first()->plan;
    $plan->forceFill(['features' => array_merge((array) $plan->features, ['rest_hooks' => false])])->save();

    assertProblem($this->getJson('/api/v1/webhook-subscriptions', apiHeaders($plain)), 404, 'not-found');
});

test('token sem webhooks:manage: 403 missing-ability em todas as rotas', function (): void {
    ['organization' => $organization, 'owner' => $owner] = restHooksOrg();
    [$plain] = restHookToken($organization, $owner, ['envelopes:read', 'envelopes:write', 'envelopes:send', 'documents:read']);

    foreach (restHookRoutes() as [$method, $url]) {
        $body = assertProblem($this->json($method, $url, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'], apiHeaders($plain, apiIdem())), 403, 'missing-ability');
        expect($body['required_ability'])->toBe('webhooks:manage');
    }

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(0);
});

test('criador sem manage_integrations: 403 creator-lacks-permission (a ability sozinha não basta)', function (): void {
    ['organization' => $organization] = restHooksOrg();
    $member = attachMember($organization, MembershipRole::Member);
    [$plain] = apiRawToken($organization, $member, ['webhooks:manage']);

    foreach (restHookRoutes() as [$method, $url]) {
        assertProblem($this->json($method, $url, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'], apiHeaders($plain, apiIdem())), 403, 'creator-lacks-permission');
    }

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(0);
});

test('criação sem Idempotency-Key: 400', function (): void {
    ['organization' => $organization, 'owner' => $owner] = restHooksOrg();
    [$plain] = restHookToken($organization, $owner);

    assertProblem($this->postJson('/api/v1/webhook-subscriptions', ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'], apiHeaders($plain)), 400, 'idempotency-key-missing');
});
