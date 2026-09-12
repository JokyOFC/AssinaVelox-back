<?php

require_once __DIR__.'/Support/ApiHelpers.php';

/*
| Limites por token e por organização, com os cabeçalhos RateLimit-* do rascunho IETF, e o
| balde por origem para tentativas com token inválido.
*/

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
});

test('toda resposta traz os cabeçalhos RateLimit do balde mais apertado', function () {
    $token = apiIssueToken($this->organization, $this->owner);

    $response = $this->getJson('/api/v1/envelopes', apiHeaders($token))->assertOk();

    expect($response->headers->get('RateLimit-Limit'))->toBe('120')
        ->and($response->headers->get('RateLimit-Remaining'))->toBe('119')
        ->and((int) $response->headers->get('RateLimit-Reset'))->toBeGreaterThan(0)
        ->and($response->headers->get('RateLimit-Policy'))->toBe('120;w=60, 600;w=60');
});

test('limite por token: estourado, 429 com Retry-After — e renova depois da janela', function () {
    config()->set('assinavelox.api.rate_limit.per_token_per_minute', 2);
    $token = apiIssueToken($this->organization, $this->owner);

    $this->getJson('/api/v1/envelopes', apiHeaders($token))->assertOk();
    $this->getJson('/api/v1/envelopes', apiHeaders($token))->assertOk()->assertHeader('RateLimit-Remaining', '0');

    $blocked = $this->getJson('/api/v1/envelopes', apiHeaders($token));
    assertProblem($blocked, 429, 'rate-limited');
    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($blocked->headers->get('RateLimit-Remaining'))->toBe('0');

    $this->travel(61)->seconds();
    $this->getJson('/api/v1/envelopes', apiHeaders($token))->assertOk();
});

test('limite por organização vale para todos os tokens dela — e só dela', function () {
    config()->set('assinavelox.api.rate_limit.per_token_per_minute', 100);
    config()->set('assinavelox.api.rate_limit.per_organization_per_minute', 3);

    $first = apiIssueToken($this->organization, $this->owner);
    $second = apiIssueToken($this->organization, $this->owner);

    $this->getJson('/api/v1/envelopes', apiHeaders($first))->assertOk();
    $this->getJson('/api/v1/envelopes', apiHeaders($first))->assertOk();
    $this->getJson('/api/v1/envelopes', apiHeaders($second))->assertOk();
    assertProblem($this->getJson('/api/v1/envelopes', apiHeaders($second)), 429, 'rate-limited');

    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    apiEnable($other);
    $this->getJson('/api/v1/envelopes', apiHeaders(apiIssueToken($other, $otherOwner)))->assertOk();
});

test('tentativas com token inválido são limitadas por origem', function () {
    config()->set('assinavelox.api.rate_limit.failed_auth_per_minute', 3);
    $valid = apiIssueToken($this->organization, $this->owner);

    for ($i = 0; $i < 3; $i++) {
        assertProblem($this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->getJson('/api/v1/envelopes', apiHeaders('1|chute-'.$i)), 401);
    }

    assertProblem($this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->getJson('/api/v1/envelopes', apiHeaders('1|chute-final')), 429, 'rate-limited');
    assertProblem($this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->getJson('/api/v1/envelopes', apiHeaders($valid)), 429, 'rate-limited');

    // Outra origem não é afetada.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson('/api/v1/envelopes', apiHeaders($valid))->assertOk();
});
