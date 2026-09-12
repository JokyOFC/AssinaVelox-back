<?php

use App\Models\Envelope;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/Support/ApiHelpers.php';

/*
| Contrato de erros RFC 9457: a mesma forma (type, title, status, detail, instance,
| correlation_id; `errors` no 422) para 400, 401, 403, 404, 405, 409, 422, 429 e 500 — sem
| stack, sem mensagem interna, sem dado nenhum.
*/

beforeEach(function () {
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    apiEnable($this->organization);
    $this->token = apiIssueToken($this->organization, $this->owner);
});

test('todos os status de erro têm a mesma forma', function () {
    $completed = Envelope::factory()->forOrganization($this->organization, $this->owner)->completed()->create();
    $readOnly = apiIssueToken($this->organization, $this->owner, ['envelopes:read']);

    $cases = [
        [400, 'idempotency-key-missing', fn () => $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], apiHeaders($this->token))],
        [401, 'unauthenticated', fn () => $this->getJson('/api/v1/envelopes')],
        [403, 'missing-ability', fn () => $this->postJson('/api/v1/envelopes', ['title' => 'Contrato'], apiHeaders($readOnly, apiIdem()))],
        [404, 'not-found', fn () => $this->getJson('/api/v1/envelopes/01HZZZZZZZZZZZZZZZZZZZZZZZ', apiHeaders($this->token))],
        [405, 'method-not-allowed', fn () => $this->deleteJson('/api/v1/envelopes', [], apiHeaders($this->token))],
        [409, 'invalid-status', fn () => $this->postJson('/api/v1/envelopes/'.$completed->ulid.'/cancel', [], apiHeaders($this->token))],
        [422, 'validation-failed', fn () => $this->postJson('/api/v1/envelopes', ['title' => 'x', 'signing_order' => 'aleatoria'], apiHeaders($this->token, apiIdem()))],
    ];

    foreach ($cases as [$status, $slug, $call]) {
        $body = assertProblem($call(), $status, $slug);

        expect($body['title'])->toBeString()->not->toBe('')
            ->and($body['instance'])->toStartWith('/api/');
    }
});

test('422 traz os erros por campo', function () {
    $body = assertProblem($this->postJson('/api/v1/envelopes', ['title' => 'x', 'signing_order' => 'aleatoria', 'expires_in_days' => 999], apiHeaders($this->token, apiIdem())), 422, 'validation-failed');

    expect($body['errors'])->toHaveKeys(['title', 'signing_order', 'expires_in_days'])
        ->and($body['errors']['title'][0])->toBeString();
});

test('405 informa os métodos aceitos no cabeçalho Allow', function () {
    $response = $this->deleteJson('/api/v1/envelopes', [], apiHeaders($this->token));

    assertProblem($response, 405, 'method-not-allowed');
    expect((string) $response->headers->get('Allow'))->toContain('GET');
});

test('429 tem a mesma forma e Retry-After', function () {
    config()->set('assinavelox.api.rate_limit.per_token_per_minute', 1);

    $this->getJson('/api/v1/envelopes', apiHeaders($this->token))->assertOk();
    $response = $this->getJson('/api/v1/envelopes', apiHeaders($this->token));

    assertProblem($response, 429, 'rate-limited');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

test('500 não vaza stack, mensagem nem dado interno — nem com debug ligado', function () {
    config()->set('app.debug', true);

    Route::middleware('api')->prefix('api')
        ->get('v1/_teste/falha', fn () => throw new RuntimeException('SQLSTATE[42S02] segredo interno na tabela personal_access_tokens'))
        ->name('api.teste.falha');
    app('router')->getRoutes()->refreshNameLookups();

    $response = $this->getJson('/api/v1/_teste/falha', apiHeaders($this->token));
    $body = assertProblem($response, 500, 'internal-error');
    $raw = (string) $response->getContent();

    foreach (['SQLSTATE', 'segredo', 'personal_access_tokens', 'RuntimeException', '.php', 'vendor'] as $leak) {
        expect($raw)->not->toContain($leak);
    }

    expect($body['detail'])->toContain('correlation_id');
});

test('mensagens em inglês do framework nunca viram detail', function () {
    $body = assertProblem($this->getJson('/api/v1/envelopes/01HZZZZZZZZZZZZZZZZZZZZZZZ', apiHeaders($this->token)), 404);

    expect($body)->not->toHaveKey('detail')
        ->and(json_encode($body))->not->toContain('No query results')
        ->and(json_encode($body))->not->toContain('App\\\\Models');
});
