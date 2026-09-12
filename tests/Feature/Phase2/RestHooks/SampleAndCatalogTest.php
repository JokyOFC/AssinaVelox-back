<?php

use App\Enums\AuditEventType;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\RestHooks\RestHookSamples;
use App\Services\Webhooks\WebhookEventType;
use App\Services\Webhooks\WebhookPayloadFactory;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/RestHookHelpers.php';

/*
|--------------------------------------------------------------------------
| REST Hooks — catálogo e payload de exemplo (para o editor do n8n/Zapier/Make)
|--------------------------------------------------------------------------
| O exemplo nunca usa dado real e tem exatamente a forma de uma entrega de verdade.
*/

beforeEach(function (): void {
    ['organization' => $this->organization, 'owner' => $this->owner] = restHooksOrg([], ['name' => 'Imobiliária Horizonte Real']);
    [$this->plain] = restHookToken($this->organization, $this->owner);
});

test('o catálogo lista os eventos assináveis (sem o evento de teste)', function (): void {
    $response = $this->getJson('/api/v1/webhook-events', apiHeaders($this->plain))->assertOk();

    expect(array_column($response->json('data'), 'value'))->toBe(WebhookEventType::subscribableValues())
        ->and(array_column($response->json('data'), 'value'))->not->toContain('webhook.ping');
});

test('o exemplo de cada evento não traz nenhum dado real da organização', function (): void {
    Queue::fake();

    $envelope = Envelope::factory()->forOrganization($this->organization, $this->owner)->inProgress()->create(['title' => 'Contrato Confidencial Apto 302']);
    $recipient = Recipient::factory()->forEnvelope($envelope)->notified()->create(['name' => 'Maria Real Souza', 'email' => 'maria.real@exemplo.com']);

    foreach (WebhookEventType::subscribableValues() as $event) {
        $response = $this->getJson('/api/v1/webhook-events/'.$event.'/sample', apiHeaders($this->plain))->assertOk();
        $json = json_encode($response->json());

        expect($response->json('meta.sample'))->toBeTrue()
            ->and($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.type'))->toBe($event);

        foreach ([$this->organization->ulid, $envelope->ulid, $recipient->ulid, 'Maria', 'maria.real', 'Confidencial', 'Horizonte', $this->owner->email] as $real) {
            expect($json)->not->toContain($real);
        }
    }
});

test('o exemplo tem a mesma forma de uma entrega real e os mesmos rótulos honestos', function (string $event, AuditEventType $audit): void {
    Queue::fake();

    ['envelope' => $envelope, 'recipient' => $recipient] = webhookEnvelope($this->organization, $this->owner);
    $auditEvent = recordAudit($envelope, $audit, $recipient);
    $type = WebhookEventType::from($event);

    $real = json_decode(app(WebhookPayloadFactory::class)->forAuditEvent($auditEvent, $type, $this->organization, $envelope->fresh(), $recipient->fresh()), true);
    $sample = $this->getJson('/api/v1/webhook-events/'.$event.'/sample', apiHeaders($this->plain))->assertOk()->json('data.0');

    expect(payloadShape($sample))->toBe(payloadShape($real));

    if ($type->isRecipientEvent()) {
        foreach (['action_label', 'meaning_label'] as $label) {
            if (isset($real['data']['recipient'][$label])) {
                expect($sample['data']['recipient'][$label])->toBe($real['data']['recipient'][$label]);
            }
        }
    }
})->with([
    'enviado' => ['envelope.sent', AuditEventType::EnvelopeSent],
    'aceite' => ['recipient.signed', AuditEventType::AcceptanceRecorded],
    'abertura' => ['recipient.viewed', AuditEventType::InvitationOpened],
    'recusa' => ['recipient.refused', AuditEventType::RecipientRefused],
    'concluído' => ['envelope.completed', AuditEventType::EnvelopeCompleted],
    'cancelado' => ['envelope.canceled', AuditEventType::EnvelopeCanceled],
]);

test('o exemplo de conclusão usa o rótulo da verificação pública (aceite eletrônico), nunca "assinatura digital"', function (): void {
    $sample = app(RestHookSamples::class)->forEvent(WebhookEventType::EnvelopeCompleted);

    expect($sample['data']['signature']['status'])->toBe('none')
        ->and(mb_strtolower((string) json_encode($sample, JSON_UNESCAPED_UNICODE)))->not->toContain('assinatura digital');
});

test('evento desconhecido ou o de teste: 404', function (): void {
    assertProblem($this->getJson('/api/v1/webhook-events/document.signed/sample', apiHeaders($this->plain)), 404, 'not-found');
    assertProblem($this->getJson('/api/v1/webhook-events/webhook.ping/sample', apiHeaders($this->plain)), 404, 'not-found');
});
