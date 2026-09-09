<?php

use App\Enums\AuditEventType;
use App\Enums\DeliveryPurpose;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\AuditEvent;
use App\Models\DeliveryAttempt;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\ResendInvitations;
use App\Services\Envelopes\Sending\SendEnvelope;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SendingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
});

// Helper global do Pest: o arquivo inteiro compartilha o mesmo escopo de funções,
// então uma redeclaração em outro arquivo de teste seria erro fatal.
if (! function_exists('sentEnvelope')) {
    /**
     * Envelope já enviado, pronto para exercitar o reenvio.
     *
     * @return array{0: Envelope, 1: Recipient}
     */
    function sentEnvelope(array $recipients = [['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']], SigningOrder $order = SigningOrder::Sequential): array
    {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
        setPlanQuota($organization, null);

        $envelope = readyEnvelope($organization, $owner, $recipients, $order);

        app(SendEnvelope::class)->handle($envelope);

        $envelope = $envelope->fresh();

        test()->organization = $organization;
        test()->owner = $owner;

        return [$envelope, $envelope->recipients()->firstOrFail()];
    }
}

test('o reenvio emite um link novo, revoga o anterior e grava invitation.resent', function () {
    [$envelope, $recipient] = sentEnvelope();

    $before = RecipientAccessLink::withoutOrganizationScope()->firstOrFail();

    app(ResendInvitations::class)->one($envelope, $recipient);

    $links = RecipientAccessLink::withoutOrganizationScope()->orderBy('id')->get();

    expect($links)->toHaveCount(2)
        ->and($links[0]->getKey())->toBe($before->getKey())
        ->and($links[0]->fresh()->revoked_at)->not->toBeNull()
        ->and($links[1]->revoked_at)->toBeNull()
        ->and($links[1]->token_digest)->not->toBe($before->token_digest);

    expect($recipient->fresh()->notification_count)->toBe(2)
        ->and(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::InvitationResent->value)->count())->toBe(1);

    $attempt = DeliveryAttempt::withoutOrganizationScope()->latest('id')->firstOrFail();

    expect($attempt->purpose)->toBe(DeliveryPurpose::Resend);
});

test('o reenvio respeita o intervalo de 10 minutos por destinatário', function () {
    [$envelope, $recipient] = sentEnvelope();

    $resends = app(ResendInvitations::class);

    $resends->one($envelope, $recipient);

    expect(fn () => $resends->one($envelope, $recipient->fresh()))
        ->toThrow(SendingException::class, 'O convite foi reenviado há pouco.');

    expect(RecipientAccessLink::withoutOrganizationScope()->count())->toBe(2);

    // Passado o intervalo, volta a ser permitido.
    RateLimiter::clear('invitation-resend:'.$recipient->getKey());

    $resends->one($envelope, $recipient->fresh());

    expect(RecipientAccessLink::withoutOrganizationScope()->count())->toBe(3);
});

test('o reenvio respeita o máximo configurável por destinatário', function () {
    [$envelope, $recipient] = sentEnvelope();

    $organization = $envelope->organization;
    $settings = $organization->settings ?? [];
    $settings['max_resends'] = 2;
    $organization->forceFill(['settings' => $settings])->save();

    $resends = app(ResendInvitations::class);

    foreach (range(1, 2) as $i) {
        RateLimiter::clear('invitation-resend:'.$recipient->getKey());
        $resends->one($envelope->fresh(), $recipient->fresh());
    }

    RateLimiter::clear('invitation-resend:'.$recipient->getKey());

    expect(fn () => $resends->one($envelope->fresh(), $recipient->fresh()))
        ->toThrow(SendingException::class, 'número máximo de reenvios (2)');
});

test('no sequencial, quem ainda aguarda a vez não recebe reenvio', function () {
    [$envelope] = sentEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ]);

    $carlos = $envelope->recipients()->where('email', 'carlos@exemplo.com')->firstOrFail();

    expect(fn () => app(ResendInvitations::class)->one($envelope, $carlos))
        ->toThrow(SendingException::class, 'ainda não chegou a vez dele');
});

test('quem já assinou não recebe reenvio', function () {
    [$envelope, $recipient] = sentEnvelope();

    $recipient->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => now()])->save();

    expect(fn () => app(ResendInvitations::class)->one($envelope, $recipient->fresh()))
        ->toThrow(SendingException::class, 'não está mais aguardando assinatura');
});

test('lembrar pendentes reenvia para todos os elegíveis e conta os ignorados', function () {
    [$envelope] = sentEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
        ['name' => 'Ana Prado', 'email' => 'ana@exemplo.com'],
    ], SigningOrder::Parallel);

    $resends = app(ResendInvitations::class);

    // O primeiro já foi reenviado agora há pouco: fica de fora do lote.
    $maria = $envelope->recipients()->where('email', 'maria@exemplo.com')->firstOrFail();
    $resends->one($envelope, $maria);

    $result = $resends->all($envelope->fresh());

    expect($result['sent'])->toBe(2)
        ->and($result['skipped'])->toBe(1);
});

test('reenvio pela rota HTTP responde com flash de sucesso e bloqueia a repetição', function () {
    [$envelope, $recipient] = sentEnvelope();

    actingAsMember($this->owner, $this->organization);

    $this->post(route('envelopes.recipients.resend', ['envelope' => $envelope, 'recipient' => $recipient]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Convite reenviado para Maria Alves.');

    $this->post(route('envelopes.recipients.resend', ['envelope' => $envelope, 'recipient' => $recipient]))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'reenviado há pouco'));
});

test('reenvio em envelope que não está em andamento é recusado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    $recipient = $envelope->recipients()->firstOrFail();

    expect(fn () => app(ResendInvitations::class)->one($envelope, $recipient))
        ->toThrow(SendingException::class, 'Ação indisponível no status atual.');
});

test('canResend reflete intervalo, limite e vez do destinatário', function () {
    [$envelope, $recipient] = sentEnvelope();

    $resends = app(ResendInvitations::class);

    expect($resends->canResend($envelope, $recipient))->toBeTrue();

    $resends->one($envelope, $recipient);

    expect($resends->canResend($envelope->fresh(), $recipient->fresh()))->toBeFalse()
        ->and($resends->cooldownMinutes($recipient))->toBeGreaterThan(0)
        ->and($resends->resendCount($recipient->fresh()))->toBe(1);
});

test('lembrar todos os pendentes da tela Assinaturas enfileira o job e trava por uma hora', function () {
    [$envelope] = sentEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ], SigningOrder::Parallel);

    actingAsMember($this->owner, $this->organization);

    $this->post(route('recipients.resend_pending'))
        ->assertRedirect()
        ->assertSessionHas('success', 'Reenviando convites para 2 signatário(s) pendente(s).');

    // Com QUEUE_CONNECTION=sync o job já rodou: os dois foram reenviados.
    expect(RecipientAccessLink::withoutOrganizationScope()->count())->toBe(4);

    $this->post(route('recipients.resend_pending'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'já foram disparados há pouco'));
});
