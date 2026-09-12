<?php

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Resources\Api\ApiTokenResource;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Services\Api\ApiTokenManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/ApiHelpers.php';

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
});

test('sem token: 401 problem+json com WWW-Authenticate: Bearer', function () {
    $response = $this->getJson('/api/v1/envelopes');

    assertProblem($response, 401, 'unauthenticated');
    expect($response->headers->get('WWW-Authenticate'))->toBe('Bearer');
});

test('token incorreto ou malformado: 401 sem dizer o motivo', function () {
    $token = apiIssueToken($this->organization, $this->owner);
    [$id] = explode('|', $token, 2);

    foreach (['lixo', '999999|'.str_repeat('a', 48), $id.'|avk_errado', $id.'|', '|'.$token, str_repeat('x', 300)] as $candidate) {
        $body = assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($candidate)), 401, 'unauthenticated');

        expect($body['detail'])->toContain('ausente, incorreto, expirado ou revogado');
    }
});

test('token revogado: 401', function () {
    $new = app(ApiTokenManager::class)->issue(apiMembership($this->organization, $this->owner), 'CRM', ['envelopes:read']);

    $this->getJson('/api/v1/envelopes', apiHeaders($new->plainTextToken))->assertOk();

    app(ApiTokenManager::class)->revoke($new->token, apiMembership($this->organization, $this->owner));

    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($new->plainTextToken)), 401, 'unauthenticated');
});

test('token expirado: 401', function () {
    $token = apiIssueToken($this->organization, $this->owner, ['envelopes:read'], Carbon::now()->addDay());

    $this->getJson('/api/v1/envelopes', apiHeaders($token))->assertOk();

    $this->travel(2)->days();

    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($token)), 401, 'unauthenticated');
});

test('criador suspenso, removido ou com a conta bloqueada: o token deixa de valer', function () {
    $admin = attachMember($this->organization, MembershipRole::Admin);
    $token = apiIssueToken($this->organization, $admin, ['envelopes:read']);
    $membership = Membership::query()->where('user_id', $admin->id)->where('organization_id', $this->organization->id);

    $this->getJson('/api/v1/envelopes', apiHeaders($token))->assertOk();

    $membership->update(['status' => MembershipStatus::Suspended->value]);
    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($token)), 401);

    $membership->update(['status' => MembershipStatus::Active->value]);
    $this->getJson('/api/v1/envelopes', apiHeaders($token))->assertOk();

    DB::table('users')->where('id', $admin->id)->update(['blocked_at' => Carbon::now()]);
    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($token)), 401);

    DB::table('users')->where('id', $admin->id)->update(['blocked_at' => null]);
    $membership->delete();
    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($token)), 401);
});

test('um token com tokenable diferente do criador não autentica', function () {
    $other = attachMember($this->organization, MembershipRole::Admin);
    [$token] = apiRawToken($this->organization, $this->owner, null, ['tokenable_id' => $other->id]);

    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($token)), 401);
});

test('o token é guardado só como hash SHA-256 e o texto aparece uma única vez', function () {
    $new = app(ApiTokenManager::class)->issue(apiMembership($this->organization, $this->owner), 'ERP', ['envelopes:read', 'envelopes:write']);

    [$id, $secret] = explode('|', $new->plainTextToken, 2);

    expect((int) $id)->toBe($new->token->id)
        ->and($secret)->toStartWith('avk_')
        ->and(strlen($secret))->toBe(4 + 40 + 8);

    $row = (array) DB::table('personal_access_tokens')->where('id', $id)->first();

    expect($row['token'])->toBe(hash('sha256', $secret))
        ->and(json_encode($row))->not->toContain($secret)
        ->and($row['token_prefix'])->toBe(substr($secret, 0, 8))
        ->and($row['organization_id'])->toBe($this->organization->id)
        ->and($row['created_by_user_id'])->toBe($this->owner->id);

    // Nenhuma leitura posterior devolve o texto ou o hash.
    $resource = json_encode((new ApiTokenResource(ApiToken::withoutOrganizationScope()->findOrFail($id)))->resolve());
    expect($resource)->not->toContain($secret)
        ->and($resource)->not->toContain(hash('sha256', $secret))
        ->and(json_encode(ApiToken::withoutOrganizationScope()->findOrFail($id)->toArray()))->not->toContain(hash('sha256', $secret));

    // A trilha registra a criação sem o segredo.
    $event = AuditEvent::query()->where('event_type', AuditEventType::ApiTokenCreated->value)->firstOrFail();
    expect($event->envelope_id)->toBeNull()
        ->and($event->payload['token'])->toBe($new->token->ulid)
        ->and($event->payload['abilities'])->toBe(['envelopes:read', 'envelopes:write'])
        ->and(json_encode($event->payload))->not->toContain($secret)
        ->and(json_encode($event->payload))->not->toContain(substr($secret, 0, 8));
});

test('o uso atualiza last_used_at e last_used_ip no máximo uma vez por minuto', function () {
    $new = app(ApiTokenManager::class)->issue(apiMembership($this->organization, $this->owner), 'CRM', ['envelopes:read']);

    expect($new->token->fresh()->last_used_at)->toBeNull();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->getJson('/api/v1/envelopes', apiHeaders($new->plainTextToken))->assertOk();
    $first = $new->token->fresh()->last_used_at;

    expect($first)->not->toBeNull()
        ->and($new->token->fresh()->last_used_ip)->toBe('198.51.100.20');

    $this->travel(20)->seconds();
    $this->getJson('/api/v1/envelopes', apiHeaders($new->plainTextToken))->assertOk();
    expect($new->token->fresh()->last_used_at->equalTo($first))->toBeTrue();

    $this->travel(2)->minutes();
    $this->getJson('/api/v1/envelopes', apiHeaders($new->plainTextToken))->assertOk();
    expect($new->token->fresh()->last_used_at->greaterThan($first))->toBeTrue();
});

test('a sessão web não autentica a API (sem cookie, sem token transiente)', function () {
    actingAsMember($this->owner, $this->organization);

    assertProblem($this->getJson('/api/v1/envelopes'), 401, 'unauthenticated');
});
