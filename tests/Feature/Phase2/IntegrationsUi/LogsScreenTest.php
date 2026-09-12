<?php

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require_once __DIR__.'/../RestHooks/Support/RestHookHelpers.php';

/*
|--------------------------------------------------------------------------
| Integrações → Logs (D-PLAT): requisições da API, só metadados, da própria organização
|--------------------------------------------------------------------------
*/

function apiLogRow(Organization $organization, ?ApiToken $token, int $status, array $attributes = []): ApiRequestLog
{
    $log = new ApiRequestLog;
    $log->forceFill(array_merge([
        'organization_id' => $organization->id,
        'personal_access_token_id' => $token?->id,
        'method' => 'GET',
        'route' => 'api.v1.envelopes.index',
        'path_pattern' => 'api/v1/envelopes',
        'status' => $status,
        'duration_ms' => 42,
        'correlation_id' => (string) Str::ulid(),
        'idempotent_replay' => false,
        'occurred_at' => Carbon::now()->subMinutes(5),
    ], $attributes))->save();

    return $log;
}

beforeEach(function (): void {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
    $this->plain = apiIssueToken($this->organization, $this->owner, ['envelopes:read']);
    $this->token = ApiToken::withoutOrganizationScope()->sole();
    actingAsMember($this->owner, $this->organization);
});

test('lista só as requisições da organização, com resumo e sem nenhum segredo', function (): void {
    apiLogRow($this->organization, $this->token, 200);
    apiLogRow($this->organization, $this->token, 404);
    apiLogRow($this->organization, $this->token, 500, ['method' => 'POST', 'route' => 'api.v1.envelopes.store']);
    apiLogRow($this->organization, $this->token, 200, ['occurred_at' => Carbon::now()->subDays(10)]);

    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    apiLogRow($other, null, 200, ['correlation_id' => 'outra-organizacao']);

    $props = inertiaPage(inertiaGet(route('integrations.logs')))['props'];
    $json = json_encode($props);

    expect($props['logs']['data'])->toHaveCount(3)
        ->and($props['summary'])->toBe(['total' => 3, 'success' => 1, 'client_error' => 1, 'server_error' => 1])
        ->and($props['filters'])->toBe(['token' => null, 'status' => null, 'period' => '7d'])
        ->and($props['logs']['data'][0])->toHaveKeys(['method', 'path', 'status', 'duration_ms', 'correlation_id', 'token'])
        ->and($props['logs']['data'][0])->not->toHaveKeys(['ip', 'body', 'headers'])
        ->and($props['tokens'])->toBe([['id' => $this->token->ulid, 'name' => $this->token->name]])
        ->and($json)->not->toContain('outra-organizacao')
        ->and($json)->not->toContain(explode('|', $this->plain, 2)[1])
        ->and($json)->not->toContain($this->token->token);
});

test('filtros por resultado, período e chave', function (): void {
    apiLogRow($this->organization, $this->token, 201);
    apiLogRow($this->organization, $this->token, 422);
    apiLogRow($this->organization, null, 503);
    apiLogRow($this->organization, $this->token, 200, ['occurred_at' => Carbon::now()->subDays(20)]);

    expect(inertiaPage(inertiaGet(route('integrations.logs', ['status' => 'client_error'])))['props']['logs']['data'])->toHaveCount(1)
        ->and(inertiaPage(inertiaGet(route('integrations.logs', ['period' => '30d'])))['props']['summary']['total'])->toBe(4)
        ->and(inertiaPage(inertiaGet(route('integrations.logs', ['token' => $this->token->ulid])))['props']['summary']['total'])->toBe(2);

    $this->get(route('integrations.logs', ['status' => 'qualquer']))->assertSessionHasErrors('status');
});
