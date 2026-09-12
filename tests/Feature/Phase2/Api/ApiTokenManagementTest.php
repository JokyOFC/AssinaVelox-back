<?php

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Http\Requests\Api\StoreApiTokenRequest;
use App\Http\Resources\Api\ApiTokenResource;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Services\Api\ApiTokenManager;
use App\Support\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/ApiHelpers.php';

/*
| Gestão de tokens (contrato para a tela Integrações → Chaves): só quem tem
| `manage_integrations` cria e revoga; limite de chaves ativas; validade; trilha.
*/

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
    $this->manager = app(ApiTokenManager::class);
    $this->ownerMembership = apiMembership($this->organization, $this->owner);
});

test('emite com nome, abilities na ordem do catálogo e validade opcional', function () {
    $expires = Carbon::now()->addDays(30);

    $new = $this->manager->issue($this->ownerMembership, '  ERP Financeiro  ', ['templates:read', 'envelopes:read', 'envelopes:read'], $expires);

    expect($new->token->name)->toBe('ERP Financeiro')
        ->and($new->token->abilityValues())->toBe(['envelopes:read', 'templates:read'])
        ->and($new->token->expires_at->equalTo($expires->startOfSecond()) || $new->token->expires_at->diffInSeconds($expires, true) < 1)->toBeTrue()
        ->and($new->token->organization_id)->toBe($this->organization->id)
        ->and($new->token->created_by_user_id)->toBe($this->owner->id)
        ->and($new->token->ulid)->toHaveLength(26);
});

test('administrador emite; operador não emite nem revoga', function () {
    $admin = attachMember($this->organization, MembershipRole::Admin);
    $member = attachMember($this->organization, MembershipRole::Member);

    $new = $this->manager->issue(apiMembership($this->organization, $admin), 'CRM', ['envelopes:read']);

    expect(fn () => $this->manager->issue(apiMembership($this->organization, $member), 'CRM', ['envelopes:read']))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->manager->revoke($new->token, apiMembership($this->organization, $member)))->toThrow(AuthorizationException::class);
});

test('revogar: marca revoked_at e quem revogou, registra na trilha e é idempotente', function () {
    $new = $this->manager->issue($this->ownerMembership, 'CRM', ['envelopes:read']);

    expect($this->manager->revoke($new->token, $this->ownerMembership))->toBeTrue()
        ->and($this->manager->revoke($new->token->fresh(), $this->ownerMembership))->toBeFalse();

    $token = $new->token->fresh();
    expect($token->revoked_at)->not->toBeNull()
        ->and($token->revoked_by_user_id)->toBe($this->owner->id)
        ->and($token->state())->toBe('revoked')
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ApiTokenRevoked->value)->count())->toBe(1);
});

test('não revoga chave de outra organização', function () {
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    apiEnable($other);
    $foreign = $this->manager->issue(apiMembership($other, $otherOwner), 'Outra', ['envelopes:read']);

    expect(fn () => $this->manager->revoke($foreign->token, $this->ownerMembership))->toThrow(AuthorizationException::class)
        ->and($foreign->token->fresh()->revoked_at)->toBeNull();
});

test('limite de chaves ativas por organização (revogadas e vencidas não contam)', function () {
    config()->set('assinavelox.api.tokens.max_active_per_organization', 2);

    $first = $this->manager->issue($this->ownerMembership, 'A', ['envelopes:read']);
    $this->manager->issue($this->ownerMembership, 'B', ['envelopes:read']);

    expect(fn () => $this->manager->issue($this->ownerMembership, 'C', ['envelopes:read']))->toThrow(ValidationException::class);

    $this->manager->revoke($first->token, $this->ownerMembership);
    $this->manager->issue($this->ownerMembership, 'C', ['envelopes:read']);

    expect(ApiTokenManager::forOrganization($this->organization)->usable()->count())->toBe(2);
});

test('validade no passado ou além do teto: recusada', function () {
    expect(fn () => $this->manager->issue($this->ownerMembership, 'A', ['envelopes:read'], Carbon::now()->subMinute()))->toThrow(ValidationException::class)
        ->and(fn () => $this->manager->issue($this->ownerMembership, 'A', ['envelopes:read'], Carbon::now()->addDays(ApiTokenManager::maxExpirationDays() + 1)))->toThrow(ValidationException::class)
        ->and(fn () => $this->manager->issue($this->ownerMembership, str_repeat('n', 121), ['envelopes:read']))->toThrow(ValidationException::class);
});

test('StoreApiTokenRequest: autoriza só manage_integrations e valida o formato', function () {
    $request = new StoreApiTokenRequest;

    CurrentOrganization::instance()->set($this->organization, $this->ownerMembership);
    expect($request->authorize())->toBeTrue();

    $member = attachMember($this->organization, MembershipRole::Member);
    CurrentOrganization::instance()->set($this->organization, apiMembership($this->organization, $member));
    expect($request->authorize())->toBeFalse();

    CurrentOrganization::instance()->clear();

    $valid = Validator::make(['name' => 'ERP', 'abilities' => ['envelopes:read'], 'expires_at' => Carbon::now()->addDays(10)->toDateTimeString()], $request->rules());
    $invalid = Validator::make(['name' => '', 'abilities' => ['envelopes:read', 'envelopes:read', 'tudo'], 'expires_at' => '2000-01-01'], $request->rules());

    expect($valid->passes())->toBeTrue()
        ->and($invalid->errors()->keys())->toContain('name', 'abilities.0', 'abilities.2', 'expires_at');
});

test('ApiTokenResource: o que a tela mostra — sem texto nem hash do token', function () {
    $new = $this->manager->issue($this->ownerMembership, 'ERP', ['envelopes:read', 'envelopes:send']);
    $data = (new ApiTokenResource(ApiToken::withoutOrganizationScope()->with('creator')->findOrFail($new->token->id)))->resolve();
    [, $secret] = explode('|', $new->plainTextToken, 2);

    expect($data)->toHaveKeys(['id', 'name', 'prefix', 'abilities', 'state', 'created_by', 'created_at', 'last_used_at', 'last_used_ip', 'expires_at', 'revoked_at'])
        ->and($data['prefix'])->toBe(substr($secret, 0, 8).'…')
        ->and($data['state'])->toBe('active')
        ->and($data['created_by'])->toBe($this->owner->name)
        ->and(collect($data['abilities'])->pluck('value')->all())->toBe(['envelopes:read', 'envelopes:send'])
        ->and(json_encode($data))->not->toContain($secret)
        ->and(json_encode($data))->not->toContain(hash('sha256', $secret));
});
