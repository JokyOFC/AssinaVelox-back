<?php

use App\Models\ApiRequestLog;
use App\Models\Envelope;
use Illuminate\Auth\Access\AuthorizationException;

require_once __DIR__.'/Support/ApiHelpers.php';

/*
| Flag `api_integrations` (roadmap T8): desligada por padrão; com ela desligada `/api/v1`
| não existe (404) e nenhuma chave é emitida.
*/

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
});

test('a flag nasce desligada', function () {
    expect(config('assinavelox.features.api_integrations'))->toBeFalse();
});

test('flag desligada: /api/v1 responde 404 problem+json, com ou sem token, e nada é gravado', function () {
    apiEnable($this->organization);
    $token = apiIssueToken($this->organization, $this->owner);
    config()->set('assinavelox.features.api_integrations', false);

    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($token)), 404, 'not-found');
    assertProblem($this->getJson('/api/v1/envelopes'), 404, 'not-found');
    assertProblem($this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], apiHeaders($token, apiIdem())), 404, 'not-found');
    assertProblem($this->getJson('/api/v1/templates', apiHeaders($token)), 404, 'not-found');

    expect(Envelope::withoutOrganizationScope()->count())->toBe(0)
        ->and(ApiRequestLog::withoutOrganizationScope()->count())->toBe(0);
});

test('interruptor global ligado e plano sem a flag: o token autentica e recebe 404; sem token, 401', function () {
    config()->set('assinavelox.features.api_integrations', true);
    [$token] = apiRawToken($this->organization, $this->owner);

    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($token)), 404, 'not-found');
    assertProblem($this->getJson('/api/v1/envelopes'), 401, 'unauthenticated');
});

test('plano com a flag e interruptor global desligado: continua 404', function () {
    apiEnable($this->organization);
    [$token] = apiRawToken($this->organization, $this->owner);
    config()->set('assinavelox.features.api_integrations', false);

    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($token)), 404);
});

test('com a flag desligada nenhuma chave é emitida', function () {
    expect(fn () => apiIssueToken($this->organization, $this->owner))->toThrow(AuthorizationException::class);
});

test('flag ligada (global e plano): a API responde', function () {
    apiEnable($this->organization);
    $token = apiIssueToken($this->organization, $this->owner);

    $this->getJson('/api/v1/envelopes', apiHeaders($token))->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
});

test('rota inexistente em /api/v1 também é problem+json 404', function () {
    apiEnable($this->organization);
    $token = apiIssueToken($this->organization, $this->owner);

    assertProblem($this->getJson('/api/v1/nao-existe', apiHeaders($token)), 404, 'not-found');
    assertProblem($this->getJson('/api/v2/envelopes', apiHeaders($token)), 404, 'not-found');
});
