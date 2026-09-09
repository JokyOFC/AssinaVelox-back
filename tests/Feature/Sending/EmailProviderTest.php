<?php

use App\Enums\DeliveryStatus;
use App\Enums\EnvelopeStatus;
use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Dto\DeliveryReceipt;
use App\Integrations\Dto\DeliveryReceiptStatus;
use App\Integrations\Dto\OutboundEmail;
use App\Integrations\Email\DeliveryContext;
use App\Integrations\Email\DeliveryRecorder;
use App\Integrations\Email\LaravelMailEmailProvider;
use App\Integrations\Email\LogEmailProvider;
use App\Models\DeliveryAttempt;
use App\Services\Envelopes\Sending\SendEnvelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SendingHelpers.php';

beforeEach(fn () => $this->withoutVite());

// Helper global do Pest: o arquivo inteiro compartilha o mesmo escopo de funções,
// então uma redeclaração em outro arquivo de teste seria erro fatal.
if (! function_exists('outboundEmail')) {
    function outboundEmail(string $correlationId = 'CORRELATION0000000000000AB'): OutboundEmail
    {
        return new OutboundEmail(
            toAddress: 'maria@exemplo.com',
            toName: 'Maria Alves',
            subject: 'Documento para assinar',
            htmlBody: '<p>Olá</p>',
            textBody: 'Olá',
            correlationId: $correlationId,
        );
    }
}

test('o mailer do Laravel devolve "sent" e nunca "delivered"', function () {
    $receipt = app(LaravelMailEmailProvider::class)->send(outboundEmail());

    expect($receipt->status)->toBe(DeliveryReceiptStatus::Sent)
        ->and($receipt->provider)->toStartWith('laravel_mail:')
        ->and($receipt->error)->toBeNull();

    // A mensagem realmente passou pelo transporte (mailer `array` do ambiente de teste).
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
});

test('um mailer que só escreve no log devolve recibo inconclusivo, não sucesso', function () {
    config(['mail.default' => 'log', 'assinavelox.email.mailer' => 'log']);

    $receipt = app(LaravelMailEmailProvider::class)->send(outboundEmail());

    expect($receipt->status)->toBe(DeliveryReceiptStatus::Unknown)
        ->and($receipt->isFailure())->toBeFalse()
        ->and($receipt->error)->toContain('nada foi transmitido');
});

test('mailer inexistente vira falha legível, sem exceção vazando', function () {
    config(['assinavelox.email.mailer' => 'inexistente']);

    $provider = app(LaravelMailEmailProvider::class);

    expect($provider->isConfigured())->toBeFalse();

    $receipt = $provider->send(outboundEmail());

    expect($receipt->status)->toBe(DeliveryReceiptStatus::Failed)
        ->and($receipt->error)->toContain('não está configurado');
});

test('o LogEmailProvider é um fake explícito: recibo unknown e corpo fora do log', function () {
    Log::spy();

    $receipt = app(LogEmailProvider::class)->send(outboundEmail());

    expect($receipt->status)->toBe(DeliveryReceiptStatus::Unknown)
        ->and($receipt->provider)->toBe('log_fake')
        ->and($receipt->error)->toContain('nada foi transmitido');

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context): bool {
        return str_contains($message, '[FAKE]')
            && ! array_key_exists('html', $context)
            && ! array_key_exists('body', $context);
    });
});

test('a configuração escolhe o provedor ligado ao contrato', function () {
    config(['assinavelox.email.provider' => 'log']);
    expect(app(EmailProvider::class))->toBeInstanceOf(LogEmailProvider::class);

    config(['assinavelox.email.provider' => 'laravel']);
    expect(app(EmailProvider::class))->toBeInstanceOf(LaravelMailEmailProvider::class);
});

test('delivery_attempts registra sent sem delivered; delivered exige evidência do provedor', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    fakeEmailProvider();

    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);

    $attempt = DeliveryAttempt::withoutOrganizationScope()->firstOrFail();

    expect($attempt->status)->toBe(DeliveryStatus::Sent)
        ->and($attempt->sent_at)->not->toBeNull()
        ->and($attempt->delivered_at)->toBeNull();

    // Só a evidência do provedor (webhook/consulta) promove a linha a "entregue".
    app(DeliveryRecorder::class)->markDelivered($attempt, ['event' => 'delivered', 'provider_id' => 'abc']);

    $attempt->refresh();

    expect($attempt->status)->toBe(DeliveryStatus::Delivered)
        ->and($attempt->delivered_at)->not->toBeNull()
        ->and($attempt->meta['delivery_evidence']['event'])->toBe('delivered');
});

test('resposta inconclusiva do provedor vira unknown, não sucesso', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    fakeEmailProvider('unknown');

    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);

    $attempt = DeliveryAttempt::withoutOrganizationScope()->firstOrFail();

    expect($attempt->status)->toBe(DeliveryStatus::Unknown)
        ->and($attempt->sent_at)->toBeNull()
        ->and($attempt->delivered_at)->toBeNull()
        ->and($attempt->error_message)->toContain('inconclusiva');
});

test('falha do provedor não derruba o envio, mas fica registrada como failed', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    fakeEmailProvider('failed');

    $envelope = readyEnvelope($organization, $owner);

    // O envio continua válido: o link existe e o destinatário está notificado. O que falhou
    // foi a ENTREGA da mensagem, e é isso que a linha registra — para o reenvio manual.
    app(SendEnvelope::class)->handle($envelope);

    $attempt = DeliveryAttempt::withoutOrganizationScope()->firstOrFail();

    expect($attempt->status)->toBe(DeliveryStatus::Failed)
        ->and($attempt->error_message)->toContain('Servidor recusou')
        ->and($attempt->sent_at)->toBeNull();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress);
});

test('a repetição com o mesmo correlation_id não duplica linha nem reenvia o que já saiu', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $provider = fakeEmailProvider();

    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);

    $envelope = $envelope->fresh();
    $recipient = $envelope->recipients()->firstOrFail();
    $attempt = DeliveryAttempt::withoutOrganizationScope()->firstOrFail();

    $context = DeliveryContext::forRecipient($recipient, $attempt->purpose, $attempt->correlation_id);
    $recorder = app(DeliveryRecorder::class);

    // Já concluída com sucesso: o canal devolve a linha existente sem chamar o provedor.
    expect($recorder->successFor($context)?->getKey())->toBe($attempt->getKey());

    // Uma tentativa que falhou é reaproveitada (mesma linha), com contador de retentativas.
    $recorder->record($attempt, DeliveryReceipt::failed('fake_provider', 'timeout'));

    expect($recorder->successFor($context))->toBeNull();

    $recorder->queue($context, 'fake_provider');

    expect(DeliveryAttempt::withoutOrganizationScope()->count())->toBe(1)
        ->and($attempt->fresh()->meta['retries'])->toBe(1)
        ->and($provider->count())->toBe(1);
});

test('o e-mail montado não carrega rastreadores', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $provider = fakeEmailProvider();

    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);

    $body = $provider->sent[0]->htmlBody;

    expect($body)->not->toContain('<img')
        ->and($body)->not->toContain('tracking')
        ->and($body)->not->toContain('utm_')
        // O botão aponta direto para a rota da aplicação, sem redirecionador de cliques.
        ->and($body)->toMatch('#href="[^"]*/assinar/[A-Za-z0-9_-]{43}"#');

    expect($provider->sent[0]->textBody)->not->toBeNull();
});
