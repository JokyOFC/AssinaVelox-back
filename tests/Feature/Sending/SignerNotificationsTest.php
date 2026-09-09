<?php

use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\DeliveryAttempt;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Sending\EnvelopeNotifications;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Organizations\NotificationPreferences;
use App\Services\Signing\Contracts\SignerNotifications;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Ponte entre o fluxo público do signatário e o envio
|--------------------------------------------------------------------------
| O módulo do signatário (App\Services\Signing) MUDA O ESTADO — avança a vez, marca
| `refused`/`canceled`, revoga links e sessões — e delega a MENSAGEM a esta camada pelo
| contrato SignerNotifications. Estes testes cobrem o lado de cá da ponte.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
});

test('a implementação registrada no container é a da camada de envio', function () {
    expect(app(SignerNotifications::class))->toBeInstanceOf(EnvelopeNotifications::class);
});

test('o convite do próximo, quando chega a vez, emite link e notifica', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ], SigningOrder::Sequential);

    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $carlos = $envelope->recipients()->where('email', 'carlos@exemplo.com')->firstOrFail();

    expect($carlos->status)->toBe(RecipientStatus::Pending)
        ->and($this->provider->to('carlos@exemplo.com'))->toHaveCount(0);

    app(SignerNotifications::class)->inviteRecipients($envelope, [$carlos]);

    expect($carlos->fresh()->status)->toBe(RecipientStatus::Notified)
        ->and($carlos->fresh()->notification_count)->toBe(1)
        ->and($this->provider->to('carlos@exemplo.com'))->toHaveCount(1);

    expect(RecipientAccessLink::withoutOrganizationScope()
        ->where('recipient_id', $carlos->getKey())
        ->whereNull('revoked_at')
        ->count())->toBe(1);

    $attempt = DeliveryAttempt::withoutOrganizationScope()
        ->where('to_address', 'carlos@exemplo.com')
        ->firstOrFail();

    expect($attempt->purpose)->toBe(DeliveryPurpose::Invitation)
        ->and($attempt->status)->toBe(DeliveryStatus::Sent);
});

test('a recusa avisa o remetente com o motivo', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $maria = $envelope->recipients()->firstOrFail();
    $maria->forceFill([
        'status' => RecipientStatus::Refused,
        'refused_at' => now(),
        'refusal_reason' => 'Os valores não conferem com o combinado.',
    ])->save();

    app(SignerNotifications::class)->notifySenderRefused($envelope, $maria->fresh());

    $toOwner = $this->provider->to($owner->email);

    expect($toOwner)->toHaveCount(1)
        ->and($toOwner[0]->subject)->toContain('Assinatura recusada')
        ->and($toOwner[0]->htmlBody)->toContain('Os valores não conferem com o combinado.');

    $attempt = DeliveryAttempt::withoutOrganizationScope()
        ->where('to_address', $owner->email)
        ->firstOrFail();

    expect($attempt->purpose)->toBe(DeliveryPurpose::Refused)
        ->and($attempt->status)->toBe(DeliveryStatus::Sent)
        ->and($attempt->delivered_at)->toBeNull()
        ->and($attempt->meta['audience'])->toBe('sender');

    // Sino do app: o canal `database` também recebe.
    expect($owner->fresh()->notifications()->count())->toBe(1);
});

test('as preferências do remetente desligam o aviso de recusa', function () {
    ['organization' => $organization, 'owner' => $owner, 'membership' => $membership] = createOrganizationWithOwner();

    app(NotificationPreferences::class)->save($membership, ['recipient_refused' => []]);

    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $recipient = $envelope->recipients()->firstOrFail();
    $recipient->forceFill([
        'status' => RecipientStatus::Refused,
        'refused_at' => now(),
        'refusal_reason' => 'Não reconheço este documento.',
    ])->save();

    app(SignerNotifications::class)->notifySenderRefused($envelope, $recipient->fresh());

    expect($this->provider->to($owner->email))->toHaveCount(0)
        ->and($owner->fresh()->notifications()->count())->toBe(0);
});

test('o encerramento por recusa avisa os convidados com o texto da recusa', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ], SigningOrder::Parallel);

    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    // Estado já aplicado pelo fluxo público.
    $carlos = $envelope->recipients()->where('email', 'carlos@exemplo.com')->firstOrFail();
    $carlos->forceFill(['status' => RecipientStatus::Canceled])->save();

    $envelope->forceFill([
        'status' => EnvelopeStatus::Refused,
        'refused_at' => now(),
        'settings' => array_replace($envelope->settings ?? [], [
            'refusal_reason' => 'Os valores não conferem.',
        ]),
    ])->save();

    app(SignerNotifications::class)->notifyEnvelopeClosed($envelope->fresh(), [$carlos->fresh()], 'recipient_refused');

    $message = $this->provider->lastTo('carlos@exemplo.com');

    expect($message->htmlBody)->toContain('um dos signatários recusou assinar')
        ->and($message->htmlBody)->toContain('Os valores não conferem.')
        ->and($message->htmlBody)->not->toContain('cancelou a solicitação');

    $attempt = DeliveryAttempt::withoutOrganizationScope()
        ->where('to_address', 'carlos@exemplo.com')
        ->where('purpose', DeliveryPurpose::Refused->value)
        ->firstOrFail();

    expect($attempt->status)->toBe(DeliveryStatus::Sent);
});

test('quem nunca foi convidado não recebe aviso de encerramento', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ], SigningOrder::Sequential);

    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    // Carlos aguardava a vez: nunca soube que o documento existia.
    $carlos = $envelope->recipients()->where('email', 'carlos@exemplo.com')->firstOrFail();

    expect($carlos->notification_count)->toBe(0);

    $before = $this->provider->count();

    app(SignerNotifications::class)->notifyEnvelopeClosed($envelope, [$carlos], 'envelope_canceled');

    expect($this->provider->count())->toBe($before);
});
