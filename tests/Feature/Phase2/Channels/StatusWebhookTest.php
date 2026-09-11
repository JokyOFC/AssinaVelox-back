<?php

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Enums\WebhookProcessingStatus;
use App\Integrations\Sms\FakeSmsProvider;
use App\Integrations\WhatsApp\FakeWhatsAppProvider;
use App\Models\ChannelStatusReceipt;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Webhook de status de SMS/WhatsApp: HMAC + janela + idempotência
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('assinavelox.channels.status_webhook.simulated_secret', 'segredo-de-teste-canais');
    ['organization' => $this->organization] = createOrganizationWithOwner();
});

it('responde 503 com o motivo quando o provedor não aceita avisos', function () {
    $body = (string) json_encode(['message_id' => 'sim-sms-x', 'status' => 'delivered']);

    config()->set('assinavelox.channels.status_webhook.simulated_secret', null);

    channelsPostStatus($this, 'webhooks.sms.status', $body, channelsSignedHeaders($body))
        ->assertStatus(503)
        ->assertJson([
            'error' => 'channel_status_disabled',
            'reason' => 'O webhook de status de SMS está desativado: o provedor não está configurado nesta instalação.',
        ]);

    // Adaptador de produção: desabilitado mesmo com segredo do simulador.
    config()->set('assinavelox.channels.status_webhook.simulated_secret', 'segredo-de-teste-canais');
    config()->set('assinavelox.channels.whatsapp.driver', 'http');

    channelsPostStatus($this, 'webhooks.whatsapp.status', $body, channelsSignedHeaders($body))->assertStatus(503);

    expect(ChannelStatusReceipt::query()->count())->toBe(0);
});

it('as rotas de status ficam fora do CSRF e com limite de taxa', function () {
    foreach (['webhooks.sms.status', 'webhooks.whatsapp.status'] as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route->excludedMiddleware())->toContain(PreventRequestForgery::class)
            ->and($route->gatherMiddleware())->toContain('throttle:webhook');
    }
});

it('recusa assinatura inválida, ausente, fora da janela ou corpo adulterado — sem gravar nada', function () {
    $attempt = channelsAttempt($this->organization, DeliveryChannel::Sms, FakeSmsProvider::NAME, 'sim-sms-1');
    $body = (string) json_encode(['event_id' => 'e1', 'message_id' => 'sim-sms-1', 'status' => 'delivered']);

    channelsPostStatus($this, 'webhooks.sms.status', $body, channelsSignedHeaders($body, secret: 'outro-segredo'))
        ->assertStatus(401)
        ->assertExactJson(['error' => 'invalid_signature']);

    channelsPostStatus($this, 'webhooks.sms.status', $body, [])->assertStatus(401);
    channelsPostStatus($this, 'webhooks.sms.status', $body, channelsSignedHeaders($body, time() - 301))->assertStatus(401);
    channelsPostStatus($this, 'webhooks.sms.status', $body, channelsSignedHeaders($body, time() + 301))->assertStatus(401);

    $headers = channelsSignedHeaders($body);
    channelsPostStatus($this, 'webhooks.sms.status', str_replace('delivered', 'failed', $body), $headers)->assertStatus(401);

    $attempt->refresh();

    expect($attempt->status)->toBe(DeliveryStatus::Unknown)
        ->and($attempt->delivered_at)->toBeNull()
        ->and(ChannelStatusReceipt::query()->count())->toBe(0);
});

it('aviso válido marca entregue com evidência; o repetido não é reprocessado', function () {
    $attempt = channelsAttempt($this->organization, DeliveryChannel::Sms, FakeSmsProvider::NAME, 'sim-sms-2');
    $body = (string) json_encode(['event_id' => 'ev-2', 'message_id' => 'sim-sms-2', 'status' => 'delivered', 'occurred_at' => now()->toIso8601String()]);
    $headers = channelsSignedHeaders($body);

    channelsPostStatus($this, 'webhooks.sms.status', $body, $headers)
        ->assertOk()
        ->assertJson(['received' => true, 'processed' => 1, 'duplicates' => 0]);

    $attempt->refresh();
    $receipt = ChannelStatusReceipt::query()->sole();

    expect($attempt->status)->toBe(DeliveryStatus::Delivered)
        ->and($attempt->delivered_at)->not->toBeNull()
        ->and($attempt->meta['delivery_evidence']['source'])->toBe('status_webhook')
        ->and($attempt->meta['delivery_evidence']['simulated'])->toBeTrue()
        ->and($attempt->meta['delivery_evidence']['receipt_id'])->toBe($receipt->id)
        ->and($receipt->signature_valid)->toBeTrue()
        ->and($receipt->is_simulated)->toBeTrue()
        ->and($receipt->processing_status)->toBe(WebhookProcessingStatus::Processed)
        ->and($receipt->delivery_attempt_id)->toBe($attempt->id);

    // Repetição (reentrega ou replay dentro da janela): não reprocessa.
    $attempt->forceFill(['status' => DeliveryStatus::Sent, 'delivered_at' => null])->save();

    channelsPostStatus($this, 'webhooks.sms.status', $body, $headers)
        ->assertOk()
        ->assertJson(['received' => true, 'processed' => 0, 'duplicates' => 1]);

    expect($attempt->fresh()->status)->toBe(DeliveryStatus::Sent)
        ->and(ChannelStatusReceipt::query()->count())->toBe(1);
});

it('aviso de mensagem desconhecida ou de outro canal é ignorado', function () {
    $whatsapp = channelsAttempt($this->organization, DeliveryChannel::Whatsapp, FakeWhatsAppProvider::NAME, 'sim-whatsapp-3');

    $body = (string) json_encode(['event_id' => 'ev-3', 'message_id' => 'sim-whatsapp-3', 'status' => 'delivered']);

    // O id é de uma mensagem de WhatsApp, mas o aviso chegou pelo webhook de SMS.
    channelsPostStatus($this, 'webhooks.sms.status', $body, channelsSignedHeaders($body))
        ->assertOk()
        ->assertJson(['processed' => 0, 'ignored' => 1]);

    expect($whatsapp->fresh()->status)->toBe(DeliveryStatus::Unknown)
        ->and(ChannelStatusReceipt::query()->sole()->error)->toBe('unknown_message');
});

it('"entregue" não é rebaixado por falha posterior; "enviado" não é "entregue"', function () {
    $attempt = channelsAttempt($this->organization, DeliveryChannel::Whatsapp, FakeWhatsAppProvider::NAME, 'sim-whatsapp-4');

    $sent = (string) json_encode(['event_id' => 'ev-4a', 'message_id' => 'sim-whatsapp-4', 'status' => 'sent']);
    channelsPostStatus($this, 'webhooks.whatsapp.status', $sent, channelsSignedHeaders($sent))->assertOk();

    expect($attempt->fresh()->status)->toBe(DeliveryStatus::Sent)
        ->and($attempt->fresh()->delivered_at)->toBeNull();

    $batch = (string) json_encode(['events' => [
        ['event_id' => 'ev-4b', 'message_id' => 'sim-whatsapp-4', 'status' => 'delivered'],
        ['event_id' => 'ev-4c', 'message_id' => 'sim-whatsapp-4', 'status' => 'failed', 'error' => 'Número inexistente'],
    ]]);

    channelsPostStatus($this, 'webhooks.whatsapp.status', $batch, channelsSignedHeaders($batch))
        ->assertOk()
        ->assertJson(['processed' => 1, 'ignored' => 1]);

    expect($attempt->fresh()->status)->toBe(DeliveryStatus::Delivered);
});
