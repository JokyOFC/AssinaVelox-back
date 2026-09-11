<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 2 — canais SMS/WhatsApp, PIN e domínios (C-CAN)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Integrations\Sms\FakeSmsProvider;
use App\Integrations\Sms\SimulatedOutbox;
use App\Integrations\Sms\StatusCallbackSignature;
use App\Integrations\WhatsApp\FakeWhatsAppProvider;
use App\Models\DeliveryAttempt;
use App\Models\Organization;
use App\Services\Signing\Channels\SenderPins;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../../Envelopes/WizardHelpers.php';

if (! function_exists('channelsEnable')) {
    /**
     * Liga (ou desliga) as flags da área: configuração global E plano vigente.
     */
    function channelsEnable(Organization $organization, bool $smsWhatsapp = true, bool $pin = false, bool $senderDomains = false): void
    {
        config()->set('assinavelox.features.sms_whatsapp', $smsWhatsapp);
        config()->set('assinavelox.features.pin_auth', $pin);
        config()->set('assinavelox.features.sender_domains', $senderDomains);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['sms_whatsapp'] = $smsWhatsapp;
        $features['pin_auth'] = $pin;
        $features['sender_domains'] = $senderDomains;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('channelsDataset')) {
    /**
     * @return array<string, array{0: AuthMethod, 1: DeliveryChannel, 2: string, 3: class-string}>
     */
    function channelsDataset(): array
    {
        return [
            'SMS' => [AuthMethod::SmsOtp, DeliveryChannel::Sms, FakeSmsProvider::NAME, FakeSmsProvider::class],
            'WhatsApp' => [AuthMethod::WhatsappOtp, DeliveryChannel::Whatsapp, FakeWhatsAppProvider::NAME, FakeWhatsAppProvider::class],
        ];
    }
}

if (! function_exists('channelsOutbox')) {
    function channelsOutbox(): SimulatedOutbox
    {
        return app(SimulatedOutbox::class);
    }
}

if (! function_exists('channelsLastCode')) {
    function channelsLastCode(DeliveryChannel $channel): string
    {
        $entry = channelsOutbox()->last($channel);

        expect($entry)->not->toBeNull('Nenhuma mensagem simulada no canal '.$channel->value);

        return (string) $entry['parameters']['code'];
    }
}

if (! function_exists('channelsSignerContext')) {
    /**
     * Envelope enviado (helpers do fluxo público) com o participante no método/telefone/PIN pedidos.
     *
     * @return array<string, mixed>
     */
    function channelsSignerContext(AuthMethod $method = AuthMethod::SmsOtp, ?string $phone = '+5511912345678', ?string $pin = null): array
    {
        $ctx = signerEnvelope();
        $recipient = $ctx['recipients']['maria@exemplo.test'];
        $recipient->forceFill(['auth_method' => $method, 'phone' => $phone])->save();

        if ($pin !== null) {
            app(SenderPins::class)->set($recipient, $pin);
        }

        $ctx['recipient'] = $recipient->fresh();
        $ctx['token'] = $ctx['tokens']['maria@exemplo.test'];
        $ctx['link'] = $ctx['links']['maria@exemplo.test'];

        return $ctx;
    }
}

if (! function_exists('channelsCaptureLogs')) {
    /**
     * Toda linha de log (mensagem + contexto CRU, antes da redação), para provar que segredo
     * nenhum chega ao log.
     *
     * @return ArrayObject<int, string>
     */
    function channelsCaptureLogs(): ArrayObject
    {
        /** @var ArrayObject<int, string> $lines */
        $lines = new ArrayObject;

        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($lines): void {
            $lines[] = $event->level.' '.$event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });

        return $lines;
    }
}

if (! function_exists('channelsDatabaseDump')) {
    /**
     * Conteúdo das tabelas onde um segredo poderia escapar.
     */
    function channelsDatabaseDump(): string
    {
        $tables = [
            'auth_challenges', 'delivery_attempts', 'audit_events', 'recipient_pins', 'recipients',
            'signing_sessions', 'channel_status_receipts', 'jobs', 'failed_jobs',
        ];

        return collect($tables)
            ->map(fn (string $table): string => DB::table($table)->get()->toJson(JSON_UNESCAPED_UNICODE))
            ->implode("\n");
    }
}

if (! function_exists('channelsAttempt')) {
    function channelsAttempt(Organization $organization, DeliveryChannel $channel, string $provider, string $messageId, DeliveryStatus $status = DeliveryStatus::Unknown): DeliveryAttempt
    {
        /** @var DeliveryAttempt */
        return DeliveryAttempt::query()->create([
            'organization_id' => $organization->id,
            'channel' => $channel,
            'provider' => $provider,
            'purpose' => DeliveryPurpose::Otp,
            'to_address' => '+5511912345678',
            'status' => $status,
            'provider_message_id' => $messageId,
            'correlation_id' => (string) Str::ulid(),
            'queued_at' => now(),
        ]);
    }
}

if (! function_exists('channelsPostStatus')) {
    /**
     * POST cru no webhook de status (a assinatura é sobre os bytes do corpo).
     *
     * @param  array<string, string>  $headers
     */
    function channelsPostStatus(object $test, string $routeName, string $body, array $headers): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $test->call('POST', route($routeName), [], [], [], $server, $body);
    }
}

if (! function_exists('channelsSignedHeaders')) {
    /**
     * @return array<string, string>
     */
    function channelsSignedHeaders(string $body, ?int $timestamp = null, string $secret = 'segredo-de-teste-canais'): array
    {
        return StatusCallbackSignature::headers($secret, $body, $timestamp);
    }
}
