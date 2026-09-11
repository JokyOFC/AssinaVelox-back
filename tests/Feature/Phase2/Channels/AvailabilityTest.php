<?php

use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Services\Signing\Channels\ChannelAvailability;
use App\Services\Signing\Channels\ChannelFeatures;

require_once __DIR__.'/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Disponibilidade por canal: o remetente não escolhe canal indisponível
|--------------------------------------------------------------------------
*/

beforeEach(fn () => $this->withoutVite());

it('com as flags desligadas, SMS e WhatsApp aparecem indisponíveis com o motivo', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $props = app(ChannelAvailability::class)->wizardProps($organization);
    $methods = collect($props['auth_methods'])->keyBy('value');

    expect(ChannelFeatures::forOrganization($organization))->toBe(['sms_whatsapp' => false, 'pin_auth' => false, 'sender_domains' => false])
        ->and($props['enabled'])->toBeFalse()
        ->and($props['channels']['email']['available'])->toBeTrue()
        ->and($props['channels']['sms']['available'])->toBeFalse()
        ->and($props['channels']['sms']['reason_code'])->toBe('feature_disabled')
        ->and($props['channels']['sms']['reason'])->toBe('O envio por SMS e WhatsApp não está habilitado para esta organização.')
        ->and($props['channels']['whatsapp']['available'])->toBeFalse()
        ->and($methods['email_otp']['available'])->toBeTrue()
        ->and($methods['sms_otp']['available'])->toBeFalse()
        ->and($methods['whatsapp_otp']['requires_phone'])->toBeTrue()
        ->and($props['pin']['enabled'])->toBeFalse();
});

it('com a flag e o simulador, o canal fica disponível e rotulado como simulado', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    channelsEnable($organization, pin: true);

    $props = app(ChannelAvailability::class)->wizardProps($organization);

    expect($props['enabled'])->toBeTrue()
        ->and($props['channels']['sms']['available'])->toBeTrue()
        ->and($props['channels']['sms']['simulated'])->toBeTrue()
        ->and($props['channels']['sms']['provider'])->toBe('sms_simulado')
        ->and($props['channels']['sms']['notice'])->toContain('simuladas')
        ->and($props['channels']['whatsapp']['available'])->toBeTrue()
        ->and($props['pin'])->toMatchArray(['enabled' => true, 'min_length' => 4, 'max_length' => 8]);
});

it('com o provedor de produção desabilitado, o remetente não consegue escolher o canal', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    channelsEnable($organization);
    config()->set('assinavelox.channels.sms.driver', 'http');

    $sms = app(ChannelAvailability::class)->describe(DeliveryChannel::Sms, $organization);

    expect($sms['available'])->toBeFalse()
        ->and($sms['reason_code'])->toBe('provider_disabled')
        ->and($sms['reason'])->toContain('desativado nesta instalação');

    $envelope = draftWithDocument($organization, $owner);
    actingAsMember($owner, $organization);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['id' => null, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'auth_method' => 'sms_otp', 'phone' => '(11) 91234-5678'],
        ],
    ])->assertSessionHasErrors(['recipients.0.auth_method' => $sms['reason']]);

    expect($envelope->fresh()->recipients()->count())->toBe(0);

    // Sem simulação (produção): o WhatsApp também some.
    config()->set('assinavelox.channels.allow_simulated', false);
    expect(app(ChannelAvailability::class)->canUse(DeliveryChannel::Whatsapp, $organization))->toBeFalse();
});

it('canal indisponível também não pode ser escolhido para o convite', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    actingAsMember($owner, $organization);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['id' => null, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'channel' => 'sms', 'phone' => '(11) 91234-5678'],
        ],
    ])->assertSessionHasErrors(['recipients.0.channel' => 'O envio por SMS e WhatsApp não está habilitado para esta organização.']);
});

it('um método já gravado é preservado quando a flag é desligada depois', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    $recipient = addRecipient($envelope, 'Maria', 'maria@exemplo.com', 1);
    $recipient->forceFill(['auth_method' => AuthMethod::SmsOtp, 'phone' => '+5511912345678'])->save();
    actingAsMember($owner, $organization);

    foreach ([[], ['auth_method' => 'sms_otp']] as $extra) {
        $this->put(route('envelopes.recipients.sync', $envelope), [
            'signing_order' => 'sequential',
            'recipients' => [
                ['id' => $recipient->ulid, 'name' => 'Maria', 'email' => 'maria@exemplo.com', ...$extra],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        expect($recipient->fresh()->auth_method)->toBe(AuthMethod::SmsOtp)
            ->and($recipient->fresh()->phone)->toBe('+5511912345678');
    }
});
