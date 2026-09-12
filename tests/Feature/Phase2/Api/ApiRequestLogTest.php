<?php

use App\Http\Resources\Api\ApiRequestLogResource;
use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\Envelope;
use App\Services\Api\ApiRequestRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Lottery;

require_once __DIR__.'/Support/ApiHelpers.php';

/*
| Registro mínimo das requisições (aba "Logs"): token, rota, status, duração, correlação —
| sem corpo, IP ou dado pessoal — e retenção curta.
*/

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
    $this->token = apiIssueToken($this->organization, $this->owner);
    $this->tokenModel = ApiToken::withoutOrganizationScope()->latest('id')->firstOrFail();
});

test('a tabela só tem metadados (sem corpo, cabeçalho, IP ou URL com parâmetros)', function () {
    expect(Schema::getColumnListing('api_request_logs'))->toEqualCanonicalizing([
        'id', 'ulid', 'organization_id', 'personal_access_token_id', 'method', 'route', 'path_pattern',
        'status', 'duration_ms', 'correlation_id', 'idempotent_replay', 'occurred_at',
    ]);
});

test('cada requisição autenticada gera um registro com rota, status, duração e correlação', function () {
    $envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->draft()->create(['title' => 'Contrato sigiloso']);

    $response = $this->getJson('/api/v1/envelopes/'.$envelope->ulid, apiHeaders($this->token))->assertOk();

    $log = ApiRequestLog::withoutOrganizationScope()->sole();

    expect($log->organization_id)->toBe($this->organization->id)
        ->and($log->personal_access_token_id)->toBe($this->tokenModel->id)
        ->and($log->method)->toBe('GET')
        ->and($log->route)->toBe('api.v1.envelopes.show')
        ->and($log->path_pattern)->toBe('api/v1/envelopes/{envelope}')
        ->and($log->status)->toBe(200)
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($log->correlation_id)->toBe($response->headers->get('X-Correlation-Id'))
        ->and(json_encode($log->toArray()))->not->toContain($envelope->ulid)
        ->and(json_encode($log->toArray()))->not->toContain('Contrato sigiloso');
});

test('erros depois da autenticação são registrados com o status; sem token, nada', function () {
    config()->set('assinavelox.api.rate_limit.per_token_per_minute', 2);

    $this->getJson('/api/v1/envelopes/01HZZZZZZZZZZZZZZZZZZZZZZZ', apiHeaders($this->token))->assertNotFound();
    $this->postJson('/api/v1/envelopes', ['title' => 'x'], apiHeaders($this->token))->assertStatus(400);
    $this->getJson('/api/v1/envelopes', apiHeaders($this->token))->assertStatus(429);
    $this->getJson('/api/v1/envelopes')->assertUnauthorized();

    expect(ApiRequestLog::withoutOrganizationScope()->orderBy('id')->pluck('status')->all())->toBe([404, 400, 429]);
});

test('repetição idempotente fica marcada', function () {
    $headers = apiHeaders($this->token, apiIdem('log-0001'));

    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], $headers)->assertCreated();
    $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], $headers)->assertCreated();

    expect(ApiRequestLog::withoutOrganizationScope()->orderBy('id')->pluck('idempotent_replay')->all())->toBe([false, true]);
});

test('retenção curta: registros vencidos saem pelo prune e pela limpeza oportunista', function () {
    config()->set('assinavelox.api.request_logs.retention_days', 30);

    $old = ApiRequestLog::query()->create([
        'organization_id' => $this->organization->id,
        'personal_access_token_id' => $this->tokenModel->id,
        'method' => 'GET',
        'route' => 'api.v1.envelopes.index',
        'path_pattern' => 'api/v1/envelopes',
        'status' => 200,
        'duration_ms' => 12,
        'occurred_at' => Carbon::now()->subDays(31),
    ]);

    expect((new ApiRequestLog)->prunable()->pluck('id')->all())->toBe([$old->id]);

    Lottery::alwaysWin();
    try {
        $this->getJson('/api/v1/envelopes', apiHeaders($this->token))->assertOk();
    } finally {
        Lottery::determineResultNormally();
    }

    expect(ApiRequestLog::withoutOrganizationScope()->whereKey($old->id)->exists())->toBeFalse()
        ->and(ApiRequestLog::withoutOrganizationScope()->count())->toBe(1)
        ->and(app(ApiRequestRecorder::class)->pruneExpired())->toBe(0);
});

test('ApiRequestLogResource: o que a aba de logs mostra', function () {
    $this->getJson('/api/v1/envelopes', apiHeaders($this->token))->assertOk();

    $data = (new ApiRequestLogResource(ApiRequestLog::withoutOrganizationScope()->with('token')->sole()))->resolve();

    expect($data)->toHaveKeys(['id', 'method', 'route', 'path', 'status', 'duration_ms', 'correlation_id', 'idempotent_replay', 'token', 'occurred_at'])
        ->and($data['path'])->toBe('/api/v1/envelopes')
        ->and($data['token'])->toBe(['id' => $this->tokenModel->ulid, 'name' => 'Integração de teste'])
        ->and($data['occurred_at'])->toEndWith('Z');
});
