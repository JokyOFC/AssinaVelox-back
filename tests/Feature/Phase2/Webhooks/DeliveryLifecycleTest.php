<?php

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEndpointPausedNotification;
use App\Services\Webhooks\WebhookFanOut;
use App\Services\Webhooks\WebhookSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Entrega, retentativa, teto, pausa automática, reenvio e idempotência
|--------------------------------------------------------------------------
| docs/fase-2/webhooks.md §5. Relógio congelado; Http::fake().
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-11 12:00:00');

    $context = webhookOrg();
    $this->organization = $context['organization'];
    $this->owner = $context['owner'];
    ['endpoint' => $this->endpoint, 'secret' => $this->secret] = makeEndpoint($this->organization, $this->owner);
    ['envelope' => $this->envelope, 'recipient' => $this->recipient] = webhookEnvelope($this->organization, $this->owner);
});

it('evento da trilha vira entrega ENFILEIRADA (job só com o id) na fila configurada', function (): void {
    Queue::fake();

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->deliveryId === $delivery->id
        && $job->trigger === DeliverWebhook::TRIGGER_INITIAL
        && $job->queue === 'default');

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_PENDING)
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->event_type)->toBe('envelope.sent')
        ->and($delivery->envelope_id)->toBe($this->envelope->id)
        ->and($delivery->organization_id)->toBe($this->organization->id);

    $serialized = serialize(Queue::pushed(DeliverWebhook::class)->first());
    expect($serialized)->not->toContain($this->secret)
        ->and($serialized)->not->toContain(WEBHOOK_TEST_URL);
});

it('entrega com sucesso registra histórico (status, código, duração, trecho) e zera falhas', function (): void {
    Http::fake(['*' => Http::response('recebido', 200)]);

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_response_code)->toBe(200)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->history)->toHaveCount(1)
        ->and($delivery->history[0])->toMatchArray([
            'attempt' => 1,
            'trigger' => 'automatic',
            'outcome' => 'succeeded',
            'response_code' => 200,
            'response_excerpt' => 'recebido',
        ])
        ->and($delivery->history[0]['duration_ms'])->toBeInt();

    $endpoint = $this->endpoint->fresh();
    expect($endpoint->consecutive_failures)->toBe(0)
        ->and($endpoint->last_success_at)->not->toBeNull();
});

it('retentativa com backoff exponencial (1 min → 24 h) e teto de 8 tentativas', function (): void {
    Http::fake(['*' => Http::response('erro', 500)]);

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_error)->toBe('http_500')
        ->and($delivery->next_retry_at->equalTo(Carbon::now()->addMinute()))->toBeTrue();

    // Antes da hora, a varredura não tenta de novo.
    Carbon::setTestNow(Carbon::now()->addSeconds(59));
    $this->artisan('webhooks:retry')->assertSuccessful();
    expect($delivery->fresh()->attempts)->toBe(1);
    Carbon::setTestNow(Carbon::now()->subSeconds(59));

    $waits = [60, 300, 1800, 7200, 43200, 86400, 86400];

    foreach ($waits as $index => $wait) {
        Carbon::setTestNow(Carbon::now()->addSeconds($wait));
        $this->artisan('webhooks:retry')->assertSuccessful();

        $delivery->refresh();
        expect($delivery->attempts)->toBe($index + 2);

        if ($index + 2 < 8) {
            expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
                ->and($delivery->next_retry_at->equalTo(Carbon::now()->addSeconds($waits[$index + 1])))->toBeTrue();
        }
    }

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_EXHAUSTED)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($delivery->history)->toHaveCount(8);

    Http::assertSentCount(8);

    // Esgotada: nenhuma tentativa automática a mais, nunca.
    Carbon::setTestNow(Carbon::now()->addDays(5));
    $this->artisan('webhooks:retry')->assertSuccessful();
    Http::assertSentCount(8);
});

it('tempo esgotado é resultado DESCONHECIDO e agenda retentativa (o receptor deduplica)', function (): void {
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds')]);

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->last_error)->toBe('timeout')
        ->and($delivery->history[0]['outcome'])->toBe('unknown')
        ->and($delivery->next_retry_at)->not->toBeNull();
});

it('falha de conexão é falha comum', function (): void {
    Http::fake(['*' => Http::failedConnection('cURL error 7: Failed to connect')]);

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);

    expect(WebhookDelivery::withoutOrganizationScope()->sole()->history[0])
        ->toMatchArray(['outcome' => 'failed', 'error' => 'connection_failed']);
});

it('redirecionamento conta como falha e o Location nunca é seguido', function (): void {
    Http::fake(['*' => Http::response('moved', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->last_error)->toBe('redirect_not_followed')
        ->and($delivery->last_response_code)->toBe(302);

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
});

it('pausa automática após N falhas seguidas, avisa quem gerencia integrações e para as entregas', function (): void {
    config(['assinavelox.webhooks.pause_after_consecutive_failures' => 3]);
    Notification::fake();
    Http::fake(['*' => Http::response('erro', 503)]);
    $operator = attachMember($this->organization, MembershipRole::Member);

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    recordAudit($this->envelope, AuditEventType::InvitationOpened, $this->recipient);
    expect($this->endpoint->fresh()->is_active)->toBeTrue();

    recordAudit($this->envelope, AuditEventType::RecipientRefused, $this->recipient);

    $endpoint = $this->endpoint->fresh();
    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->paused_reason)->toBe(WebhookEndpoint::PAUSED_FAILURES)
        ->and($endpoint->consecutive_failures)->toBe(3);

    Notification::assertSentTo($this->owner, WebhookEndpointPausedNotification::class, function (WebhookEndpointPausedNotification $notification): bool {
        $data = $notification->toArray($this->owner);

        return $data['type'] === 'webhook_failed'
            && $data['organization_id'] === $this->organization->id
            && ! str_contains(json_encode($data), $this->secret);
    });
    Notification::assertNotSentTo($operator, WebhookEndpointPausedNotification::class);
    Notification::assertSentTimes(WebhookEndpointPausedNotification::class, 1);

    // Pausado: evento novo não gera entrega; as retentativas agendadas são canceladas.
    recordAudit($this->envelope, AuditEventType::EnvelopeCanceled);
    expect(WebhookDelivery::withoutOrganizationScope()->count())->toBe(3);

    Carbon::setTestNow(Carbon::now()->addHour());
    $this->artisan('webhooks:retry')->assertSuccessful();

    expect(WebhookDelivery::withoutOrganizationScope()->pluck('status')->unique()->values()->all())->toBe([WebhookDelivery::STATUS_CANCELED])
        ->and(WebhookDelivery::withoutOrganizationScope()->pluck('last_error')->unique()->values()->all())->toBe(['endpoint_paused']);
    Http::assertSentCount(3);
});

it('um sucesso no meio zera a contagem de falhas seguidas', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push('erro', 500)
        ->push('erro', 500)
        ->push('ok', 200)]);

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    recordAudit($this->envelope, AuditEventType::InvitationOpened, $this->recipient);
    expect($this->endpoint->fresh()->consecutive_failures)->toBe(2);

    recordAudit($this->envelope, AuditEventType::AcceptanceRecorded, $this->recipient);
    expect($this->endpoint->fresh()->consecutive_failures)->toBe(0);
});

it('reenvio manual cria nova tentativa com o MESMO id de entrega e o mesmo corpo', function (): void {
    config(['assinavelox.webhooks.max_attempts' => 1]);
    Http::fake(['*' => Http::sequence()->push('erro', 500)->push('ok', 200)]);

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_EXHAUSTED);

    actingAsMember($this->owner, $this->organization);
    $this->post(route('integrations.webhooks.deliveries.resend', [
        'webhookEndpoint' => $this->endpoint->ulid,
        'delivery' => $delivery->ulid,
    ]))->assertRedirect()->assertSessionHas('success');

    $delivery->refresh();
    [$first, $second] = webhookRequests();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->attempts)->toBe(2)
        ->and($delivery->history[1])->toMatchArray(['attempt' => 2, 'trigger' => 'manual', 'outcome' => 'succeeded'])
        ->and(headerOf($first, WebhookSignature::HEADER_DELIVERY))->toBe($delivery->ulid)
        ->and(headerOf($second, WebhookSignature::HEADER_DELIVERY))->toBe($delivery->ulid)
        ->and(headerOf($second, WebhookSignature::HEADER_ATTEMPT))->toBe('2')
        ->and($second->body())->toBe($first->body());
});

it('reenvio é recusado com o endpoint pausado', function (): void {
    Http::fake(['*' => Http::response('erro', 500)]);
    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    actingAsMember($this->owner, $this->organization);
    $this->post(route('integrations.webhooks.pause', ['webhookEndpoint' => $this->endpoint->ulid]))->assertRedirect();
    $this->post(route('integrations.webhooks.deliveries.resend', [
        'webhookEndpoint' => $this->endpoint->ulid,
        'delivery' => $delivery->ulid,
    ]))->assertSessionHas('error');

    Http::assertSentCount(1);
});

it('entrega é idempotente por (endpoint, evento, id): gancho e job duplicados não duplicam nada', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $event = recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    app(WebhookFanOut::class)->handle($event);
    app(WebhookFanOut::class)->handle($event);

    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    DeliverWebhook::dispatchSync($delivery->id, DeliverWebhook::TRIGGER_INITIAL);
    DeliverWebhook::dispatchSync($delivery->id, DeliverWebhook::TRIGGER_RETRY);

    Http::assertSentCount(1);
    expect($delivery->fresh()->attempts)->toBe(1);
});

it('a trava impede duas tentativas simultâneas da mesma entrega', function (): void {
    Queue::fake();
    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();
    $delivery->forceFill(['locked_until' => Carbon::now()->addMinute()])->save();
    Http::fake();

    app()->call([new DeliverWebhook($delivery->id, DeliverWebhook::TRIGGER_INITIAL), 'handle']);

    Http::assertNothingSent();
    expect($delivery->fresh()->attempts)->toBe(0);

    // Trava vencida (worker que morreu): a entrega volta a ser tentada.
    Carbon::setTestNow(Carbon::now()->addMinutes(2));
    app()->call([new DeliverWebhook($delivery->id, DeliverWebhook::TRIGGER_INITIAL), 'handle']);

    Http::assertSentCount(1);
    expect($delivery->fresh()->attempts)->toBe(1);
});

it('dois endpoints recebem o mesmo evento com ids de entrega diferentes e o mesmo id de evento', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    fakeDns(['outro.example.com' => [WEBHOOK_PUBLIC_IP]]);
    makeEndpoint($this->organization, $this->owner, ['envelope.sent'], 'https://outro.example.com/av');

    $event = recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    $deliveries = WebhookDelivery::withoutOrganizationScope()->orderBy('id')->get();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[0]->ulid)->not->toBe($deliveries[1]->ulid)
        ->and($deliveries->pluck('event_id')->unique()->all())->toBe([$event->ulid])
        ->and(json_decode($deliveries[0]->payload, true)['id'])->toBe($event->ulid);
});

it('só entrega os eventos assinados; eventos fora do catálogo não geram entrega', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $this->endpoint->forceFill(['events' => ['envelope.completed']])->save();

    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    recordAudit($this->envelope, AuditEventType::FieldsUpdated);
    recordAudit($this->envelope, AuditEventType::ChallengeSent, $this->recipient);
    expect(WebhookDelivery::withoutOrganizationScope()->count())->toBe(0);

    recordAudit($this->envelope, AuditEventType::EnvelopeCompleted);
    expect(WebhookDelivery::withoutOrganizationScope()->sole()->event_type)->toBe('envelope.completed');
});

it('endpoint removido: entregas abertas são canceladas e nada mais sai', function (): void {
    Queue::fake();
    recordAudit($this->envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    // Simula o job que já estava na fila quando o endpoint foi removido.
    $this->endpoint->fresh()->delete();
    Http::fake();

    app()->call([new DeliverWebhook($delivery->id, DeliverWebhook::TRIGGER_INITIAL), 'handle']);

    Http::assertNothingSent();
    expect($delivery->fresh()->status)->toBe(WebhookDelivery::STATUS_CANCELED)
        ->and($delivery->fresh()->last_error)->toBe('endpoint_removed');
});

it('webhooks:prune apaga o histórico encerrado vencido e mantém o recente', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    recordAudit($this->envelope, AuditEventType::EnvelopeSent);

    Carbon::setTestNow(Carbon::now()->addDays(31));
    recordAudit($this->envelope, AuditEventType::EnvelopeCanceled);

    $this->artisan('webhooks:prune')->assertSuccessful();

    expect(WebhookDelivery::withoutOrganizationScope()->pluck('event_type')->all())->toBe(['envelope.canceled']);
});
