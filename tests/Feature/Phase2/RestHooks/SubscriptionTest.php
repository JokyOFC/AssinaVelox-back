<?php

use App\Enums\AuditEventType;
use App\Enums\Permission;
use App\Models\ApiToken;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/RestHookHelpers.php';

/*
|--------------------------------------------------------------------------
| REST Hooks — assinar e remover (roadmap §2.17; docs/fase-2/integracoes-no-code.md)
|--------------------------------------------------------------------------
| Cada assinatura é um endpoint do motor de webhooks (D-HOOK) com `source = rest_hook`,
| ligado ao token, à organização do token e a quem o criou. SSRF, segredo e HMAC são os do
| motor. A rede nunca é acessada (DNS falso + Http::preventStrayRequests).
*/

beforeEach(function (): void {
    ['organization' => $this->organization, 'owner' => $this->owner] = restHooksOrg([
        'interno.example.com' => ['10.0.0.5'],
        'metadados.example.com' => ['169.254.169.254'],
    ]);
    [$this->plain, $this->token] = restHookToken($this->organization, $this->owner);
});

test('assinar cria um endpoint rest_hook ligado ao token, à organização e ao criador; o segredo só vem na criação', function (): void {
    $response = restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.completed']);

    $response->assertCreated();
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Content-Type'))->toStartWith('application/json');

    $data = $response->json('data');
    $endpoint = WebhookEndpoint::withoutOrganizationScope()->sole();

    expect($data['secret'])->toStartWith('whsec_')
        ->and($data['object'])->toBe('webhook_subscription')
        ->and($data['id'])->toBe($endpoint->ulid)
        ->and($data['events'])->toBe(['envelope.completed'])
        ->and($data['status'])->toBe('active')
        ->and($endpoint->source)->toBe(WebhookEndpoint::SOURCE_REST_HOOK)
        ->and($endpoint->api_token_id)->toBe($this->token->id)
        ->and($endpoint->organization_id)->toBe($this->organization->id)
        ->and($endpoint->created_by_user_id)->toBe($this->owner->id)
        ->and($response->headers->get('Location'))->toEndWith('/api/v1/webhook-subscriptions/'.$endpoint->ulid);

    // Guardado cifrado; e o armazenamento de Idempotency-Key não ficou com o segredo em claro.
    expect((string) DB::table('webhook_endpoints')->value('secret'))->not->toContain($data['secret'])
        ->and(DB::table('api_idempotency_keys')->pluck('response_body')->implode(''))->not->toContain($data['secret']);

    // A listagem nunca devolve o segredo.
    $list = $this->getJson('/api/v1/webhook-subscriptions', apiHeaders($this->plain))->assertOk();
    expect($list->json('data.0.id'))->toBe($endpoint->ulid)
        ->and($list->json('data.0'))->not->toHaveKey('secret')
        ->and(json_encode($list->json()))->not->toContain($data['secret'])
        ->and($list->json('meta.limit'))->toBe(10);
});

test('aceita vários eventos em events[] e "*" para todos', function (): void {
    restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'events' => ['recipient.signed', 'envelope.sent']])
        ->assertCreated()
        ->assertJsonPath('data.events', ['envelope.sent', 'recipient.signed']);

    restSubscribe($this->plain, ['target_url' => 'https://receiver.example.com/todos', 'event' => '*'])
        ->assertCreated()
        ->assertJsonPath('data.events', ['*']);

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(2);
});

test('repetir a assinatura (mesma chave ou mesmo pedido) não cria outra e não reexibe o segredo', function (): void {
    $body = ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.completed'];

    $first = restSubscribe($this->plain, $body, 'assinatura-zap-1')->assertCreated();
    $again = restSubscribe($this->plain, $body, 'assinatura-zap-1')->assertOk();
    $newKey = restSubscribe($this->plain, $body, 'assinatura-zap-2')->assertOk();

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(1)
        ->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($again->json('data'))->not->toHaveKey('secret')
        ->and($again->json('meta.existing'))->toBeTrue()
        ->and($newKey->json('data'))->not->toHaveKey('secret');
});

test('a proteção SSRF do motor vale: endereço interno ou IP literal dá 422 sem criar nada nem vazar o endereço', function (string $url): void {
    $body = assertProblem(restSubscribe($this->plain, ['target_url' => $url, 'event' => 'envelope.sent']), 422, 'target-url-blocked');

    expect($body['errors']['target_url'][0])->toBe($body['detail'])
        ->and(json_encode($body))->not->toContain('10.0.0.5')
        ->and(json_encode($body))->not->toContain('169.254.169.254')
        ->and(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(0);
})->with([
    'resolve para rede privada' => 'https://interno.example.com/hook',
    'resolve para metadados de nuvem' => 'https://metadados.example.com/hook',
    'IP literal' => 'https://127.0.0.1/hook',
    'IP decimal' => 'https://2130706433/hook',
]);

test('validação: URL obrigatória e evento do catálogo', function (): void {
    assertProblem(restSubscribe($this->plain, ['event' => 'envelope.sent']), 422, 'validation-failed');
    $unknown = assertProblem(restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'document.signed']), 422, 'validation-failed');
    assertProblem(restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'webhook.ping']), 422, 'validation-failed');

    expect($unknown['errors'])->toHaveKey('event')
        ->and(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(0);
});

test('limite de assinaturas por token (409) — outro token da organização ainda pode assinar', function (): void {
    config()->set('assinavelox.rest_hooks.max_subscriptions_per_token', 2);

    restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'])->assertCreated();
    restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.completed'])->assertCreated();

    $body = assertProblem(restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.expired']), 409, 'subscription-limit-reached');
    expect($body['limit'])->toBe(2);

    [$otherPlain] = restHookToken($this->organization, $this->owner);
    restSubscribe($otherPlain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.expired'])->assertCreated();

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(3);
});

test('o teto de endpoints da organização (motor) também vale: 409', function (): void {
    config()->set('assinavelox.webhooks.max_endpoints_per_organization', 1);
    makeEndpoint($this->organization, $this->owner);

    assertProblem(restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent']), 409, 'subscription-refused');
    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(1);
});

test('DELETE remove a assinatura do próprio token (204); de novo, 404', function (): void {
    $created = restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'])->assertCreated();
    $id = $created->json('data.id');
    $secretBefore = WebhookEndpoint::withoutOrganizationScope()->sole()->secret;

    $this->deleteJson('/api/v1/webhook-subscriptions/'.$id, [], apiHeaders($this->plain))->assertNoContent();

    $endpoint = WebhookEndpoint::withoutOrganizationScope()->withTrashed()->sole();
    expect($endpoint->trashed())->toBeTrue()
        ->and($endpoint->is_active)->toBeFalse()
        ->and($endpoint->secret)->not->toBe($secretBefore);

    assertProblem($this->deleteJson('/api/v1/webhook-subscriptions/'.$id, [], apiHeaders($this->plain)), 404, 'not-found');
    expect($this->getJson('/api/v1/webhook-subscriptions', apiHeaders($this->plain))->json('data'))->toBe([]);
});

test('isolamento: outro token da mesma organização, endpoint da tela e outra organização dão 404', function (): void {
    $created = restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'])->assertCreated();
    $id = $created->json('data.id');

    [$sameOrgOther] = restHookToken($this->organization, $this->owner);
    assertProblem($this->deleteJson('/api/v1/webhook-subscriptions/'.$id, [], apiHeaders($sameOrgOther)), 404, 'not-found');
    expect($this->getJson('/api/v1/webhook-subscriptions', apiHeaders($sameOrgOther))->json('data'))->toBe([]);

    $web = makeEndpoint($this->organization, $this->owner)['endpoint'];
    assertProblem($this->deleteJson('/api/v1/webhook-subscriptions/'.$web->ulid, [], apiHeaders($this->plain)), 404, 'not-found');

    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    restHooksEnable($other);
    [$foreign] = restHookToken($other, $otherOwner);
    assertProblem($this->deleteJson('/api/v1/webhook-subscriptions/'.$id, [], apiHeaders($foreign)), 404, 'not-found');

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(2)
        ->and(WebhookEndpoint::withoutOrganizationScope()->where('ulid', $id)->exists())->toBeTrue();
});

test('revogar a chave pela tela remove as assinaturas dela (e só as dela)', function (): void {
    $mine = restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'])->assertCreated()->json('data.id');
    [$otherPlain] = restHookToken($this->organization, $this->owner);
    $others = restSubscribe($otherPlain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'])->assertCreated()->json('data.id');

    actingAsMember($this->owner, $this->organization);
    $this->delete(route('integrations.keys.destroy', ['apiToken' => $this->token->ulid]))
        ->assertRedirect(route('integrations.keys'))
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'assinatura de webhook'));

    expect(WebhookEndpoint::withoutOrganizationScope()->where('ulid', $mine)->exists())->toBeFalse()
        ->and(WebhookEndpoint::withoutOrganizationScope()->where('ulid', $others)->exists())->toBeTrue()
        ->and(ApiToken::withoutOrganizationScope()->find($this->token->id)?->revoked_at)->not->toBeNull();

    assertProblem($this->getJson('/api/v1/webhook-subscriptions', apiHeaders($this->plain)), 401, 'unauthenticated');
});

test('as entregas saem pelo motor, e só de documentos que quem criou o token pode ver', function (): void {
    Queue::fake();

    restSubscribe($this->plain, ['target_url' => WEBHOOK_TEST_URL, 'event' => 'envelope.sent'])->assertCreated();

    // Integração criada por uma função personalizada SEM "ver todos os documentos".
    enableCustomRoles();
    $role = createCustomRole($this->organization, 'Integrações', [Permission::ManageIntegrations]);
    $integrator = attachWithCustomRole($this->organization, $role);
    [$integratorPlain] = restHookToken($this->organization, $integrator, ['webhooks:manage']);
    restSubscribe($integratorPlain, ['target_url' => 'https://receiver.example.com/integrador', 'event' => 'envelope.sent'])->assertCreated();

    ['envelope' => $envelope] = webhookEnvelope($this->organization, $this->owner);
    recordAudit($envelope, AuditEventType::EnvelopeSent);

    $deliveries = WebhookDelivery::withoutOrganizationScope()->get();
    $ownerEndpoint = WebhookEndpoint::withoutOrganizationScope()->where('api_token_id', $this->token->id)->sole();

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries->first()->webhook_endpoint_id)->toBe($ownerEndpoint->id)
        ->and($deliveries->first()->event_type)->toBe('envelope.sent');
});
