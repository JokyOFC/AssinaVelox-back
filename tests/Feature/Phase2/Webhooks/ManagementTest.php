<?php

use App\Enums\AuditEventType;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Gestão pela interface web — docs/fase-2/webhooks.md §9 (contrato das telas)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $context = webhookOrg();
    $this->organization = $context['organization'];
    $this->owner = $context['owner'];
    actingAsMember($this->owner, $this->organization);
});

function webhookProps($response): array
{
    return inertiaPage($response)['props'];
}

it('cria endpoint, mostra o segredo UMA vez e o guarda cifrado', function (): void {
    $response = $this->post(route('integrations.webhooks.store'), [
        'url' => WEBHOOK_TEST_URL,
        'events' => ['envelope.sent', 'envelope.completed'],
        'description' => 'ERP financeiro',
    ]);

    $endpoint = WebhookEndpoint::withoutOrganizationScope()->sole();
    $response->assertRedirect(route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid]))
        ->assertSessionHas('webhook_secret');
    $secret = session('webhook_secret')['secret'];

    expect($secret)->toStartWith('whsec_')
        ->and($endpoint->events)->toBe(['envelope.sent', 'envelope.completed'])
        ->and($endpoint->created_by_user_id)->toBe($this->owner->id)
        ->and($endpoint->secret_hint)->toBe('…'.substr($secret, -4))
        ->and((string) DB::table('webhook_endpoints')->value('secret'))->not->toContain($secret);

    $first = inertiaGet(route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid]));
    expect(inertiaPage($first)['component'])->toBe('integrations/webhooks/show')
        ->and(webhookProps($first)['revealed_secret'])->toBe(['endpoint' => $endpoint->ulid, 'secret' => $secret]);

    $second = inertiaGet(route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid]));
    expect(webhookProps($second)['revealed_secret'])->toBeNull()
        ->and(json_encode(webhookProps($second)))->not->toContain($secret);

    $index = inertiaGet(route('integrations.webhooks.index'));
    expect(inertiaPage($index)['component'])->toBe('integrations/webhooks/index');
    expect(json_encode(webhookProps($index)))->not->toContain($secret)
        ->and(webhookProps($index)['endpoints'][0])->toMatchArray([
            'id' => $endpoint->ulid,
            'host' => WEBHOOK_TEST_HOST,
            'status' => 'active',
            'events' => ['envelope.sent', 'envelope.completed'],
        ])
        ->and(collect(webhookProps($index)['catalog'])->pluck('value')->all())->toContain('recipient.signed', 'envelope.completed');
});

it('valida a forma do cadastro', function (): void {
    $this->post(route('integrations.webhooks.store'), ['url' => WEBHOOK_TEST_URL, 'events' => ['envelope.inventado']])
        ->assertSessionHasErrors('events.0');
    $this->post(route('integrations.webhooks.store'), ['url' => WEBHOOK_TEST_URL, 'events' => []])
        ->assertSessionHasErrors('events');
    $this->post(route('integrations.webhooks.store'), ['url' => WEBHOOK_TEST_URL, 'events' => ['webhook.ping']])
        ->assertSessionHasErrors('events.0');

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(0);
});

it('limite de endpoints por organização', function (): void {
    config(['assinavelox.webhooks.max_endpoints_per_organization' => 1]);

    $this->post(route('integrations.webhooks.store'), ['url' => WEBHOOK_TEST_URL, 'events' => ['*']])->assertRedirect();
    $this->post(route('integrations.webhooks.store'), ['url' => WEBHOOK_TEST_URL, 'events' => ['*']])->assertSessionHasErrors('url');

    expect(WebhookEndpoint::withoutOrganizationScope()->count())->toBe(1);
});

it('altera eventos e URL revalidando a rede', function (): void {
    ['endpoint' => $endpoint] = makeEndpoint($this->organization, $this->owner);
    fakeDns(['novo.example.com' => [WEBHOOK_PUBLIC_IP], 'interno.example.com' => ['192.168.0.10']]);

    $this->patch(route('integrations.webhooks.update', ['webhookEndpoint' => $endpoint->ulid]), [
        'url' => 'https://novo.example.com/av#x',
        'events' => ['recipient.signed'],
        'description' => 'CRM',
    ])->assertSessionHas('success');

    expect($endpoint->fresh()->url)->toBe('https://novo.example.com/av')
        ->and($endpoint->fresh()->events)->toBe(['recipient.signed'])
        ->and($endpoint->fresh()->description)->toBe('CRM');

    $this->patch(route('integrations.webhooks.update', ['webhookEndpoint' => $endpoint->ulid]), [
        'url' => 'https://interno.example.com/av',
    ])->assertSessionHasErrors('url');

    expect($endpoint->fresh()->url)->toBe('https://novo.example.com/av');
});

it('pausar e reativar (zera as falhas seguidas)', function (): void {
    ['endpoint' => $endpoint] = makeEndpoint($this->organization, $this->owner);
    $endpoint->forceFill(['consecutive_failures' => 7])->save();

    $this->post(route('integrations.webhooks.pause', ['webhookEndpoint' => $endpoint->ulid]))->assertSessionHas('success');
    expect($endpoint->fresh()->is_active)->toBeFalse()
        ->and($endpoint->fresh()->paused_reason)->toBe(WebhookEndpoint::PAUSED_MANUAL);

    $this->post(route('integrations.webhooks.resume', ['webhookEndpoint' => $endpoint->ulid]))->assertSessionHas('success');
    expect($endpoint->fresh()->is_active)->toBeTrue()
        ->and($endpoint->fresh()->consecutive_failures)->toBe(0)
        ->and($endpoint->fresh()->paused_reason)->toBeNull();
});

it('enviar teste entrega webhook.ping assinado, sem documento', function (): void {
    ['endpoint' => $endpoint] = makeEndpoint($this->organization, $this->owner, ['envelope.completed']);
    Http::fake(['*' => Http::response('pong', 200)]);

    $this->post(route('integrations.webhooks.test', ['webhookEndpoint' => $endpoint->ulid]))->assertSessionHas('success');

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();
    $payload = json_decode($delivery->payload, true);

    expect($delivery->is_test)->toBeTrue()
        ->and($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->envelope_id)->toBeNull()
        ->and($payload['type'])->toBe('webhook.ping')
        ->and($payload['data']['endpoint'])->toBe(['id' => $endpoint->ulid]);
});

it('rotação pela tela revela o novo segredo uma vez e mantém o anterior pelo período pedido', function (): void {
    Carbon::setTestNow('2026-09-11 12:00:00');
    ['endpoint' => $endpoint, 'secret' => $old] = makeEndpoint($this->organization, $this->owner);

    $this->post(route('integrations.webhooks.secret.rotate', ['webhookEndpoint' => $endpoint->ulid]), ['overlap_hours' => 2])
        ->assertRedirect(route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid]));

    $new = session('webhook_secret')['secret'];
    $endpoint->refresh();

    expect($new)->not->toBe($old)
        ->and($endpoint->signingSecrets())->toBe([$new, $old])
        ->and($endpoint->previous_secret_expires_at->equalTo(Carbon::now()->addHours(2)))->toBeTrue();

    $this->post(route('integrations.webhooks.secret.rotate', ['webhookEndpoint' => $endpoint->ulid]), ['overlap_hours' => 500])
        ->assertSessionHasErrors('overlap_hours');

    $this->post(route('integrations.webhooks.secret.expire_previous', ['webhookEndpoint' => $endpoint->ulid]))->assertSessionHas('success');
    expect($endpoint->fresh()->signingSecrets())->toBe([$new]);
});

it('remover cancela a fila, sobrescreve o segredo e some da tela', function (): void {
    ['endpoint' => $endpoint, 'secret' => $secret] = makeEndpoint($this->organization, $this->owner);
    ['envelope' => $envelope] = webhookEnvelope($this->organization, $this->owner);
    Queue::fake();
    recordAudit($envelope, AuditEventType::EnvelopeSent);

    $this->delete(route('integrations.webhooks.destroy', ['webhookEndpoint' => $endpoint->ulid]))
        ->assertRedirect(route('integrations.webhooks.index'));

    $trashed = WebhookEndpoint::withoutOrganizationScope()->withTrashed()->find($endpoint->id);

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->secret)->not->toBe($secret)
        ->and(WebhookDelivery::withoutOrganizationScope()->sole()->status)->toBe(WebhookDelivery::STATUS_CANCELED);

    $this->get(route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid]))->assertNotFound();
});

it('detalhe da entrega em JSON: histórico e corpo, sem segredo nem cabeçalho de assinatura', function (): void {
    ['endpoint' => $endpoint, 'secret' => $secret] = makeEndpoint($this->organization, $this->owner);
    ['envelope' => $envelope] = webhookEnvelope($this->organization, $this->owner);
    Http::fake(['*' => Http::response('ok', 200)]);
    recordAudit($envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    $response = $this->getJson(route('integrations.webhooks.deliveries.show', [
        'webhookEndpoint' => $endpoint->ulid,
        'delivery' => $delivery->ulid,
    ]));

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('id', $delivery->ulid)
        ->assertJsonPath('status', 'delivered')
        ->assertJsonPath('history.0.outcome', 'succeeded')
        ->assertJsonPath('history.0.trigger', 'automatic')
        ->assertJsonPath('payload.type', 'envelope.sent')
        ->assertJsonPath('envelope.id', $envelope->ulid)
        ->assertJsonPath('payload_hidden', false);

    expect($response->getContent())->not->toContain($secret)
        ->and($response->getContent())->not->toMatch('/v1=[0-9a-f]{64}/');
});

it('histórico da página filtra por status e evento', function (): void {
    ['endpoint' => $endpoint] = makeEndpoint($this->organization, $this->owner);
    ['envelope' => $envelope, 'recipient' => $recipient] = webhookEnvelope($this->organization, $this->owner);
    Http::fake(['*' => Http::sequence()->push('ok', 200)->push('erro', 500)]);
    recordAudit($envelope, AuditEventType::EnvelopeSent);
    recordAudit($envelope, AuditEventType::InvitationOpened, $recipient);

    $all = webhookProps(inertiaGet(route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid])));
    expect($all['deliveries']['meta']['total'])->toBe(2)
        ->and(array_keys($all['deliveries']))->toBe(['data', 'links', 'meta']);

    $failed = webhookProps(inertiaGet(route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid, 'status' => 'failed'])));
    expect($failed['deliveries']['data'])->toHaveCount(1)
        ->and($failed['deliveries']['data'][0]['event_type'])->toBe('recipient.viewed')
        ->and($failed['deliveries']['data'][0]['last_error_label'])->toContain('HTTP 500');

    $sent = webhookProps(inertiaGet(route('integrations.webhooks.show', ['webhookEndpoint' => $endpoint->ulid, 'event' => 'envelope.sent'])));
    expect($sent['deliveries']['data'])->toHaveCount(1)
        ->and($sent['deliveries']['data'][0]['status'])->toBe('delivered');
});
