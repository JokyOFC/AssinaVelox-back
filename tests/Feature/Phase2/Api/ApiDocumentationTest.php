<?php

use App\Enums\MembershipRole;

require_once __DIR__.'/Support/ApiHelpers.php';

/*
| Documentação OpenAPI (Scramble): só /api/v1, esquema Bearer, e acesso (interface e JSON)
| restrito a usuários autenticados com `manage_integrations` e a flag ligada. Convidado: 403.
*/

beforeEach(function () {
    $this->withoutVite();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
});

test('convidado: 403 na interface e no JSON (com a flag ligada ou não)', function () {
    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();

    apiEnable($this->organization);

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});

test('usuário sem manage_integrations: 403', function () {
    apiEnable($this->organization);
    $member = attachMember($this->organization, MembershipRole::Member);

    actingAsMember($member, $this->organization);

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});

test('proprietário com a flag desligada: 403', function () {
    actingAsMember($this->owner, $this->organization);

    $this->get('/docs/api.json')->assertForbidden();
});

test('um token de API não abre a documentação (ela é da sessão, não da API)', function () {
    apiEnable($this->organization);
    $token = apiIssueToken($this->organization, $this->owner);

    $this->get('/docs/api.json', ['Authorization' => 'Bearer '.$token])->assertForbidden();
});

test('com manage_integrations e a flag ligada: JSON só com /api/v1 e esquema Bearer', function () {
    apiEnable($this->organization);
    actingAsMember($this->owner, $this->organization);

    $spec = $this->get('/docs/api.json')->assertOk()->json();

    expect($spec['openapi'])->toStartWith('3.1')
        ->and($spec['info']['title'])->toContain('API v1')
        ->and($spec['info']['version'])->toBe('1.0.0')
        ->and($spec['servers'][0]['url'])->toEndWith('/api/v1')
        ->and($spec['components']['securitySchemes']['bearerAuth'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer'])
        ->and($spec['security'])->toBe([['bearerAuth' => []]]);

    $paths = array_keys($spec['paths']);

    expect($paths)->toContain('/envelopes', '/envelopes/{envelope}', '/envelopes/{envelope}/send', '/templates/{template}/envelopes');

    foreach ($paths as $path) {
        expect($path)->not->toContain('documentos')
            ->and($path)->not->toContain('webhooks/mercadopago')
            ->and($path)->not->toStartWith('/api/');
    }
});

test('com manage_integrations e a flag ligada: a interface abre', function () {
    apiEnable($this->organization);
    actingAsMember($this->owner, $this->organization);

    $this->get('/docs/api')->assertOk();
});
