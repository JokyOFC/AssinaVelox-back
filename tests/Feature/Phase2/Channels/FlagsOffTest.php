<?php

use App\Enums\AuditEventType;
use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Models\AuditEvent;
use App\Models\AuthChallenge;
use App\Models\RecipientPin;
use App\Services\Envelopes\Sending\InvitationDispatcher;
use App\Services\Signing\Channels\ChannelFeatures;
use App\Services\Signing\Channels\SendChannelInvitation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Flags desligadas (padrão) = comportamento da Fase 1
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/channels-off-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('as três flags nascem desligadas na configuração', function () {
    expect(config('assinavelox.features.sms_whatsapp'))->toBeFalse()
        ->and(config('assinavelox.features.pin_auth'))->toBeFalse()
        ->and(config('assinavelox.features.sender_domains'))->toBeFalse();

    ['organization' => $organization] = createOrganizationWithOwner();

    expect(ChannelFeatures::forOrganization($organization))->toBe(['sms_whatsapp' => false, 'pin_auth' => false, 'sender_domains' => false]);
});

it('o sync ignora telefone e recusa SMS, WhatsApp e PIN', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    actingAsMember($owner, $organization);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [['id' => null, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'phone' => '(11) 91234-5678', 'channel' => 'email', 'auth_method' => 'email_otp']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $recipient = $envelope->fresh()->recipients()->sole();

    expect($recipient->phone)->toBeNull()
        ->and($recipient->auth_method)->toBe(AuthMethod::EmailOtp)
        ->and($recipient->getAttribute('delivery_channel'))->toBeNull();

    foreach ([['auth_method' => 'sms_otp'], ['auth_method' => 'whatsapp_otp'], ['channel' => 'sms'], ['pin' => '48291573']] as $extra) {
        $this->put(route('envelopes.recipients.sync', $envelope), [
            'signing_order' => 'sequential',
            'recipients' => [['id' => $recipient->ulid, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'phone' => '(11) 91234-5678', ...$extra]],
        ])->assertSessionHasErrors();
    }

    expect($recipient->fresh()->auth_method)->toBe(AuthMethod::EmailOtp)
        ->and(RecipientPin::query()->count())->toBe(0);
});

it('o código continua saindo por e-mail, com a trilha da Fase 1', function () {
    $codes = signerCaptureCodes();
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];

    $this->post(route('sign.otp.send', ['token' => $token]))->assertSessionHasNoErrors();

    $sent = AuditEvent::query()->where('event_type', AuditEventType::ChallengeSent->value)->sole();

    expect(AuthChallenge::query()->sole()->channel)->toBe(DeliveryChannel::Email)
        ->and($codes)->toHaveCount(1)
        ->and(channelsOutbox()->all())->toBe([])
        ->and(array_keys($sent->payload))->toBe(['challenge_ulid', 'channel', 'expires_at', 'delivery_status']);
});

it('o convite não gera aviso por canal e os webhooks de status respondem 503', function () {
    Queue::fake();
    $ctx = signerEnvelope();
    $recipient = $ctx['recipients']['maria@exemplo.test'];

    app(InvitationDispatcher::class)->resend($recipient, $ctx['envelope']);

    Queue::assertNotPushed(SendChannelInvitation::class);

    $body = (string) json_encode(['message_id' => 'x', 'status' => 'delivered']);

    channelsPostStatus($this, 'webhooks.sms.status', $body, channelsSignedHeaders($body))->assertStatus(503);
    channelsPostStatus($this, 'webhooks.whatsapp.status', $body, channelsSignedHeaders($body))->assertStatus(503);
});
