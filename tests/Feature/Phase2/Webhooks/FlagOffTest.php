<?php

use App\Enums\AuditEventType;
use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Flag `outbound_webhooks` desligada = nenhuma entrega (roadmap T8)
|--------------------------------------------------------------------------
*/

it('nasce desligada na configuração', function (): void {
    expect(config('assinavelox.features.outbound_webhooks'))->toBeFalse();
});

it('interruptor global desligado: nenhuma entrega, nenhuma chamada e nenhuma consulta às tabelas de webhook', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    makeEndpoint($organization, $owner);
    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);
    config(['assinavelox.features.outbound_webhooks' => false]);
    Http::fake();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'webhook_'))->all())->toBe([])
        ->and(WebhookDelivery::withoutOrganizationScope()->count())->toBe(0);

    $this->artisan('webhooks:retry')->assertSuccessful();
    Http::assertNothingSent();
});

it('plano sem a flag: nenhuma entrega', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    makeEndpoint($organization, $owner);
    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);
    webhooksOn($organization, enabled: false);
    Http::fake();

    recordAudit($envelope, AuditEventType::EnvelopeSent);

    expect(WebhookDelivery::withoutOrganizationScope()->count())->toBe(0);
    Http::assertNothingSent();
});

it('flag desligada depois de enfileirar: a entrega é cancelada sem sair', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    makeEndpoint($organization, $owner);
    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);

    Queue::fake();
    recordAudit($envelope, AuditEventType::EnvelopeSent);
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    webhooksOn($organization, enabled: false);
    Http::fake();
    // Queue::fake() segura também o dispatchSync: roda o job à mão, como o worker faria.
    app()->call([new DeliverWebhook($delivery->id, DeliverWebhook::TRIGGER_INITIAL), 'handle']);

    Http::assertNothingSent();
    expect($delivery->fresh()->status)->toBe(WebhookDelivery::STATUS_CANCELED)
        ->and($delivery->fresh()->last_error)->toBe('feature_disabled');
});

it('rotas de gestão respondem 404 com a flag desligada, mesmo para o proprietário', function (): void {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('integrations.webhooks.index'))->assertNotFound();
    $this->post(route('integrations.webhooks.store'), ['url' => WEBHOOK_TEST_URL, 'events' => ['*']])->assertNotFound();

    // Global ligado, plano não: continua 404.
    config(['assinavelox.features.outbound_webhooks' => true]);
    $this->get(route('integrations.webhooks.index'))->assertNotFound();
});

it('os placeholders da Fase 1 continuam iguais', function (): void {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('integrations.logs'))->assertRedirect(route('integrations.index'));
});
