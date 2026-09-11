<?php

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Models\DeliveryAttempt;
use App\Services\Envelopes\Sending\InvitationDispatcher;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Convite pelo canal do participante (aviso adicional ao e-mail)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/channels-invite-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->logs = channelsCaptureLogs();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('envia o aviso com o link pelo canal, além do e-mail, sem o link no log', function (string $channelValue, string $template) {
    $channel = DeliveryChannel::from($channelValue);
    $ctx = signerEnvelope();
    channelsEnable($ctx['organization']);
    $recipient = $ctx['recipients']['maria@exemplo.test'];
    $recipient->forceFill(['delivery_channel' => $channel->value, 'phone' => '+5511912345678'])->save();

    app(InvitationDispatcher::class)->resend($recipient->fresh(), $ctx['envelope']);

    $entry = channelsOutbox()->last($channel);

    expect($entry)->not->toBeNull()
        ->and($entry['purpose'])->toBe(DeliveryPurpose::Resend->value)
        ->and($entry['template'])->toBe($template)
        ->and($entry['parameters']['url'])->toContain('/assinar/')
        ->and($entry['to'])->toBe('+5511912345678');

    $sms = DeliveryAttempt::query()->where('channel', $channel->value)->sole();
    $email = DeliveryAttempt::query()->where('channel', DeliveryChannel::Email->value)->sole();

    expect($sms->purpose)->toBe(DeliveryPurpose::Resend)
        ->and($sms->status)->toBe(DeliveryStatus::Unknown)
        ->and($sms->correlation_id)->toBe($email->correlation_id)
        ->and(json_encode($sms->meta))->not->toContain($entry['parameters']['url']);

    expect(implode("\n", $this->logs->getArrayCopy()))->not->toContain($entry['parameters']['url']);
})->with([
    'SMS' => ['sms', 'sms_resend'],
    'WhatsApp' => ['whatsapp', 'assinavelox_convite'],
]);

it('com o provedor indisponível, o aviso é pulado e o e-mail sai igual', function () {
    $ctx = signerEnvelope();
    channelsEnable($ctx['organization']);
    config()->set('assinavelox.channels.sms.driver', 'http');
    $recipient = $ctx['recipients']['maria@exemplo.test'];
    $recipient->forceFill(['delivery_channel' => 'sms', 'phone' => '+5511912345678'])->save();

    app(InvitationDispatcher::class)->resend($recipient->fresh(), $ctx['envelope']);

    expect(DeliveryAttempt::query()->where('channel', 'sms')->count())->toBe(0)
        ->and(DeliveryAttempt::query()->where('channel', 'email')->count())->toBe(1)
        ->and(implode("\n", $this->logs->getArrayCopy()))->toContain('channels.invitation.skipped');
});
