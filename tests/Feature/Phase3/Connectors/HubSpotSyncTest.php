<?php

use App\Enums\AuditEventType;
use App\Models\HubSpotActionExecution;
use App\Models\HubSpotConnection;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\HubSpot\Jobs\SyncHubSpotObject;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| HubSpot — atualização do negócio/contato quando o envelope muda de estado
| (docs/fase-3/conectores.md §5.3 e §5.4: chamada direta com SSRF + renovação do token)
|--------------------------------------------------------------------------
*/

const HUBSPOT_SYNC_TOKEN_URL = 'https://api.hubapi.com/oauth/2026-03/token';
const HUBSPOT_SYNC_PATCH_URL = 'https://api.hubapi.com/crm/v3/objects/deals/9001';

beforeEach(function () {
    Http::preventStrayRequests();
    connectorsDns();
    connectorsConfigure();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    connectorsEnable($this->organization);
    $this->envelope = connectorsDraft($this->organization, $this->owner);
    $this->connection = connectorsHubSpotConnection($this->organization, $this->owner, 555001);
    $this->execution = HubSpotActionExecution::withoutOrganizationScope()->create([
        'organization_id' => $this->organization->getKey(),
        'hubspot_connection_id' => $this->connection->getKey(),
        'portal_id' => 555001,
        'callback_id' => 'cb-sync',
        'object_type' => 'DEAL',
        'object_id' => '9001',
        'envelope_id' => $this->envelope->getKey(),
        'status' => HubSpotActionExecution::STATUS_SENT,
    ]);
});

test('envelope concluído grava o estado na propriedade do negócio, com o token da conexão', function () {
    Http::fake([HUBSPOT_SYNC_PATCH_URL => Http::response(['id' => '9001'], 200)]);

    EnvelopeAudit::record($this->envelope, AuditEventType::EnvelopeCompleted);

    Http::assertSent(fn (Request $request): bool => $request->url() === HUBSPOT_SYNC_PATCH_URL
        && $request->method() === 'PATCH'
        && $request['properties'] === ['assinavelox_status' => 'completed']
        && $request->header('Authorization') === ['Bearer HUBSPOT-ACCESS-SENTINELA-555001']);

    $execution = $this->execution->fresh();
    expect($execution->sync_status)->toBe(HubSpotActionExecution::SYNC_SYNCED)
        ->and($execution->synced_value)->toBe('completed')
        ->and($execution->synced_at)->not->toBeNull();
});

test('token vencido é renovado ANTES da chamada e o novo par fica cifrado', function () {
    $this->connection->forceFill(['token_expires_at' => Carbon::now()->subMinute()])->save();
    Http::fake([
        HUBSPOT_SYNC_TOKEN_URL => Http::response(['access_token' => 'HUBSPOT-ACCESS-RENOVADO', 'refresh_token' => 'HUBSPOT-REFRESH-RENOVADO', 'expires_in' => 1800]),
        HUBSPOT_SYNC_PATCH_URL => Http::response(['id' => '9001'], 200),
    ]);

    EnvelopeAudit::record($this->envelope, AuditEventType::EnvelopeRefused);

    $recorded = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->values()->all();
    expect($recorded)->toBe([HUBSPOT_SYNC_TOKEN_URL, HUBSPOT_SYNC_PATCH_URL]);

    Http::assertSent(fn (Request $request): bool => $request->url() === HUBSPOT_SYNC_TOKEN_URL
        && $request->data()['grant_type'] === 'refresh_token'
        && $request->data()['refresh_token'] === 'HUBSPOT-REFRESH-SENTINELA-555001');
    Http::assertSent(fn (Request $request): bool => $request->url() === HUBSPOT_SYNC_PATCH_URL
        && $request->header('Authorization') === ['Bearer HUBSPOT-ACCESS-RENOVADO']
        && $request['properties'] === ['assinavelox_status' => 'refused']);

    $connection = $this->connection->fresh();
    expect($connection->access_token)->toBe('HUBSPOT-ACCESS-RENOVADO')
        ->and($connection->refresh_token)->toBe('HUBSPOT-REFRESH-RENOVADO')
        ->and($connection->last_refreshed_at)->not->toBeNull()
        ->and($connection->token_expires_at->isFuture())->toBeTrue();

    $raw = DB::table('hubspot_connections')->where('id', $connection->getKey())->first();
    expect($raw->access_token)->not->toContain('RENOVADO')
        ->and(Crypt::decryptString($raw->refresh_token))->toBe('HUBSPOT-REFRESH-RENOVADO');
});

test('renovação recusada: conexão marcada para reconectar, sincronização falha e nada tenta em laço', function () {
    $this->connection->forceFill(['token_expires_at' => Carbon::now()->subMinute()])->save();
    Http::fake([HUBSPOT_SYNC_TOKEN_URL => Http::response(['status' => 'BAD_REFRESH_TOKEN'], 400)]);

    EnvelopeAudit::record($this->envelope, AuditEventType::EnvelopeCompleted);

    expect($this->connection->fresh()->status)->toBe(HubSpotConnection::STATUS_ERROR)
        ->and($this->connection->fresh()->last_error_code)->toBe('refresh_rejected')
        ->and($this->execution->fresh()->sync_status)->toBe(HubSpotActionExecution::SYNC_FAILED)
        ->and($this->execution->fresh()->sync_error_code)->toBe('reconnect_required');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === HUBSPOT_SYNC_PATCH_URL);
    Http::assertSentCount(1);
});

test('4xx do HubSpot (propriedade inexistente) encerra como falha, sem nova tentativa', function () {
    Http::fake([HUBSPOT_SYNC_PATCH_URL => Http::response(['category' => 'VALIDATION_ERROR'], 400)]);

    EnvelopeAudit::record($this->envelope, AuditEventType::EnvelopeCompleted);

    expect($this->execution->fresh()->sync_status)->toBe(HubSpotActionExecution::SYNC_FAILED)
        ->and($this->execution->fresh()->sync_error_code)->toBe('http_400');
    Http::assertSentCount(1);
});

test('eventos que não mudam o estado, envelope sem execução e objeto não suportado não saem', function () {
    Http::fake();

    EnvelopeAudit::record($this->envelope, AuditEventType::InvitationOpened);
    EnvelopeAudit::record(connectorsDraft($this->organization, $this->owner), AuditEventType::EnvelopeCompleted);

    $this->execution->forceFill(['object_type' => 'LINE_ITEM'])->save();
    EnvelopeAudit::record($this->envelope, AuditEventType::EnvelopeCompleted);

    expect($this->execution->fresh()->sync_status)->toBe(HubSpotActionExecution::SYNC_NOT_APPLICABLE);
    Http::assertNothingSent();
});

test('a fila leva só o id da execução e o estado — nunca token', function () {
    Queue::fake();

    EnvelopeAudit::record($this->envelope, AuditEventType::EnvelopeCanceled);

    Queue::assertPushed(SyncHubSpotObject::class, function (SyncHubSpotObject $job): bool {
        $serialized = serialize($job);

        return $job->executionId === $this->execution->getKey()
            && $job->status === 'canceled'
            && ! str_contains($serialized, 'SENTINELA');
    });

    expect($this->execution->fresh()->sync_status)->toBe(HubSpotActionExecution::SYNC_PENDING);
});
