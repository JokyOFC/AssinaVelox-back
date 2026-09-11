<?php

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Contracts\FiscalInvoiceProvider;
use App\Integrations\Contracts\Messaging\ChannelMessage;
use App\Integrations\Contracts\Messaging\IncomingStatusCallback;
use App\Integrations\Contracts\SenderDomainVerifier;
use App\Integrations\Contracts\SmsProvider;
use App\Integrations\Contracts\TimestampProvider;
use App\Integrations\Contracts\WhatsAppProvider;
use App\Integrations\Cpf\FakeCpfVerificationProvider;
use App\Integrations\Dto\DeliveryReceiptStatus;
use App\Integrations\Email\FakeSenderDomainVerifier;
use App\Integrations\Email\HttpSenderDomainVerifier;
use App\Integrations\Email\SenderDomainVerification;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Fiscal\FakeFiscalInvoiceProvider;
use App\Integrations\Sms\FakeSmsProvider;
use App\Integrations\Sms\HttpSmsProvider;
use App\Integrations\Sms\SimulatedMessagingProvider;
use App\Integrations\Timestamp\FakeTimestampProvider;
use App\Integrations\WhatsApp\FakeWhatsAppProvider;
use App\Integrations\WhatsApp\HttpWhatsAppProvider;
use App\Services\Branding\Contracts\VerifiedSenderDomains;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Contratos, simuladores identificados e produção desabilitada (C-CAN)
|--------------------------------------------------------------------------
| Nenhum teste acessa a rede: Http::fake() + preventStrayRequests() em todos.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    $this->logs = channelsCaptureLogs();
});

if (! function_exists('channelsMessage')) {
    function channelsMessage(string $code = '481516', string $key = 'idem-1'): ChannelMessage
    {
        return new ChannelMessage(
            toE164: '+5511912345678',
            purpose: DeliveryPurpose::Otp,
            template: 'sms_otp',
            parameters: ['code' => $code, 'ttl_minutes' => '10'],
            text: "Seu código é {$code}",
            correlationId: (string) Str::ulid(),
            idempotencyKey: $key,
            sensitive: ['code'],
        );
    }
}

it('os simuladores são identificados, registram a mensagem, avisam [SIMULADO] e não transmitem nada', function ($method, DeliveryChannel $channel, string $name, string $class) {
    $contract = $channel === DeliveryChannel::Sms ? SmsProvider::class : WhatsAppProvider::class;
    $provider = app($contract);

    expect($provider)->toBeInstanceOf($class)
        ->and($provider->name())->toBe($name)->toContain('simulado')
        ->and($provider->isSimulated())->toBeTrue()
        ->and($provider->isConfigured())->toBeTrue()
        ->and($provider->channel())->toBe($channel);

    $receipt = $provider->send(channelsMessage());

    // Registrar não é enviar: o recibo é `unknown`, nunca `sent`.
    expect($receipt->status)->toBe(DeliveryReceiptStatus::Unknown)
        ->and($receipt->provider)->toBe($name)
        ->and($receipt->providerMessageId)->toStartWith('sim-'.$channel->value.'-')
        ->and($receipt->meta['simulated'])->toBeTrue();

    $entry = channelsOutbox()->last($channel);

    expect($entry['parameters']['code'])->toBe('481516')
        ->and($entry['to'])->toBe('+5511912345678')
        ->and($entry['simulated'])->toBeTrue();

    $log = implode("\n", $this->logs->getArrayCopy());

    expect($log)->toContain('[SIMULADO]')
        ->toContain('+55 •••••••5678')
        ->not->toContain('481516')
        ->not->toContain('+5511912345678');

    Http::assertNothingSent();
})->with(channelsDataset());

it('a mesma chave de idempotência não gera segunda mensagem', function () {
    $provider = app(SmsProvider::class);

    $first = $provider->send(channelsMessage('111222', 'mesma-chave'));
    $second = $provider->send(channelsMessage('333444', 'mesma-chave'));

    expect(channelsOutbox()->all(DeliveryChannel::Sms))->toHaveCount(1)
        ->and($second->providerMessageId)->toBe($first->providerMessageId)
        ->and($second->meta['deduplicated'])->toBeTrue();
});

it('o simulador reproduz aceito, falha e tempo esgotado para os testes', function () {
    /** @var FakeSmsProvider $provider */
    $provider = app(FakeSmsProvider::class);

    $provider->simulate(SimulatedMessagingProvider::MODE_SENT);
    expect($provider->send(channelsMessage(key: 'a'))->status)->toBe(DeliveryReceiptStatus::Sent);

    $provider->simulate(SimulatedMessagingProvider::MODE_FAIL);
    expect($provider->send(channelsMessage(key: 'b'))->status)->toBe(DeliveryReceiptStatus::Failed);

    $provider->simulate(SimulatedMessagingProvider::MODE_TIMEOUT);
    expect(fn () => $provider->send(channelsMessage(key: 'c')))->toThrow(ConnectionException::class);
});

it('os adaptadores de produção estão desabilitados e dizem exatamente o que falta', function () {
    $sms = app(HttpSmsProvider::class);
    $whatsapp = app(HttpWhatsAppProvider::class);
    $domains = app(HttpSenderDomainVerifier::class);

    foreach ([$sms, $whatsapp] as $provider) {
        expect($provider->isConfigured())->toBeFalse()
            ->and($provider->acceptsStatusCallbacks())->toBeFalse()
            ->and($provider->isSimulated())->toBeFalse()
            ->and($provider->verifyStatusCallback(new IncomingStatusCallback('{}', []))->reason)->toBe('provider_disabled')
            ->and(fn () => $provider->status('qualquer'))->toThrow(ProviderDisabledException::class);
    }

    expect(fn () => $sms->send(channelsMessage()))
        ->toThrow(ProviderDisabledException::class, 'Documentação oficial da API de SMS');
    expect(implode(' ', $sms->missingRequirements()))
        ->toContain('ASSINAVELOX_SMS_BASE_URL')
        ->toContain('Formato do webhook de status')
        ->toContain('custo por mensagem');
    expect(implode(' ', $whatsapp->missingRequirements()))
        ->toContain('Templates pré-aprovados por finalidade')
        ->toContain('número da operadora e número do cliente');

    // Mesmo com as variáveis preenchidas, continua desabilitado (sem documentação, sem adaptador).
    config()->set('services.assinavelox_sms', ['base_url' => 'https://exemplo.invalid', 'credentials' => 'x', 'webhook_secret' => 'y']);
    expect($sms->isConfigured())->toBeFalse()
        ->and(implode(' ', $sms->missingRequirements()))->not->toContain('ASSINAVELOX_SMS_BASE_URL')->toContain('continua desabilitado');

    expect($domains->isConfigured())->toBeFalse()
        ->and(fn () => $domains->register('empresa.com.br', 'token'))->toThrow(ProviderDisabledException::class, 'API do serviço de e-mail');

    Http::assertNothingSent();
});

it('o binding segue a configuração', function () {
    expect(app(SmsProvider::class))->toBeInstanceOf(FakeSmsProvider::class)
        ->and(app(WhatsAppProvider::class))->toBeInstanceOf(FakeWhatsAppProvider::class)
        ->and(app(SenderDomainVerifier::class))->toBeInstanceOf(FakeSenderDomainVerifier::class)
        ->and(app(VerifiedSenderDomains::class))->toBeInstanceOf(SenderDomainVerification::class)
        ->and(app(TimestampProvider::class))->toBeInstanceOf(FakeTimestampProvider::class)
        ->and(app(FiscalInvoiceProvider::class))->toBeInstanceOf(FakeFiscalInvoiceProvider::class);

    config()->set('assinavelox.channels.sms.driver', 'http');
    config()->set('assinavelox.channels.whatsapp.driver', 'http');
    config()->set('assinavelox.sender_domains.verifier', 'http');

    expect(app(SmsProvider::class))->toBeInstanceOf(HttpSmsProvider::class)
        ->and(app(WhatsAppProvider::class))->toBeInstanceOf(HttpWhatsAppProvider::class)
        ->and(app(SenderDomainVerifier::class))->toBeInstanceOf(HttpSenderDomainVerifier::class);

    // Contrato reservado sem adaptador real: driver desconhecido é erro explícito.
    config()->set('assinavelox.integrations.timestamp.driver', 'act');
    expect(fn () => app(TimestampProvider::class))->toThrow(IntegrationException::class);
});

it('com a simulação desligada (produção), os simuladores ficam indisponíveis e não registram nada', function () {
    config()->set('assinavelox.channels.allow_simulated', false);

    $sms = app(SmsProvider::class);
    $receipt = $sms->send(channelsMessage());

    expect($sms->isConfigured())->toBeFalse()
        ->and($receipt->status)->toBe(DeliveryReceiptStatus::Failed)
        ->and(channelsOutbox()->all())->toBe([])
        ->and(implode(' ', $sms->missingRequirements()))->toContain('nunca funciona em produção')
        ->and(app(FakeSenderDomainVerifier::class)->isConfigured())->toBeFalse()
        ->and(fn () => app(TimestampProvider::class)->timestamp(hash('sha256', 'x')))->toThrow(ProviderDisabledException::class);
});

it('o carimbo simulado nunca se rotula ICP-Brasil nem finge ser token RFC 3161', function () {
    $result = app(TimestampProvider::class)->timestamp(hash('sha256', 'documento'));

    expect($result['tsa_kind'])->toBe('simulated')
        ->and($result['simulated'])->toBeTrue()
        ->and(mb_strtolower((string) json_encode($result)))->not->toContain('icp')
        ->and(base64_decode($result['token_der_base64']))->toContain('NÃO é um token RFC 3161')
        ->and(implode("\n", $this->logs->getArrayCopy()))->toContain('[SIMULADO]');

    expect(fn () => app(TimestampProvider::class)->timestamp('abc'))->toThrow(InvalidArgumentException::class);
});

it('a NFS-e simulada deixa claro que nenhuma nota foi emitida', function () {
    $provider = app(FiscalInvoiceProvider::class);
    $issued = $provider->issue(['payment_reference' => 'pay_1', 'taker' => ['cpf' => '52998224725', 'name' => 'Maria']]);

    expect($provider->isSimulated())->toBeTrue()
        ->and($issued['status'])->toBe('simulated')
        ->and(array_keys($issued))->not->toContain('number')
        ->and(array_keys($issued))->not->toContain('verification_code')
        ->and(array_keys($issued))->not->toContain('pdf_url')
        ->and(array_keys($issued))->not->toContain('xml_url')
        ->and($issued['details']['message'])->toBe(FakeFiscalInvoiceProvider::NOTICE)
        ->and($provider->cancel($issued['invoice_id'], 'teste')['status'])->toBe('simulated')
        ->and(implode("\n", $this->logs->getArrayCopy()))->not->toContain('52998224725');
});

it('a consulta de CPF simulada nunca diz "válido" sozinha e não loga o CPF', function () {
    $provider = app(FakeCpfVerificationProvider::class);

    $valid = $provider->verify('529.982.247-25');
    $invalid = $provider->verify('123.456.789-00');

    expect($valid['status'])->toBe('inconclusive')
        ->and($valid['details']['simulated'])->toBeTrue()
        ->and($valid['details']['reason_code'])->toBe('simulated')
        ->and($invalid['status'])->toBe('invalid')
        ->and($invalid['details']['reason_code'])->toBe('check_digits');

    $provider->simulate('valid');
    expect($provider->verify('52998224725')['details']['simulated'])->toBeTrue();

    $log = implode("\n", $this->logs->getArrayCopy());
    expect($log)->toContain('[SIMULADO]')->not->toContain('52998224725')->not->toContain('529.982.247-25');
});
