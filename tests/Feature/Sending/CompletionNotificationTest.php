<?php

use App\Enums\AccessLinkPurpose;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\DeliveryAttempt;
use App\Models\Envelope;
use App\Models\RecipientAccessLink;
use App\Models\VerificationRecord;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Envelopes\Sending\CompletionNotifier;
use App\Services\Envelopes\Sending\SendEnvelope;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SendingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
});

// Helper global do Pest: o arquivo inteiro compartilha o mesmo escopo de funções,
// então uma redeclaração em outro arquivo de teste seria erro fatal.
if (! function_exists('completedEnvelope')) {
    /**
     * Envelope enviado, com todos assinados e marcado `completed` — o estado em que a
     * finalização (incremento 4) chamará o CompletionNotifier.
     */
    function completedEnvelope(): Envelope
    {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

        $envelope = readyEnvelope($organization, $owner);
        app(SendEnvelope::class)->handle($envelope);
        $envelope = $envelope->fresh();

        $envelope->recipients()->firstOrFail()->forceFill([
            'status' => RecipientStatus::Signed,
            'signed_at' => now(),
        ])->save();

        $envelope->forceFill([
            'status' => EnvelopeStatus::Completed,
            'completed_at' => now(),
        ])->save();

        test()->owner = $owner;

        return $envelope->fresh();
    }
}

test('a conclusão emite link de download próprio e avisa signatários e remetente', function () {
    $envelope = completedEnvelope();

    $result = app(CompletionNotifier::class)->notify($envelope);

    expect($result['notified'])->toBe(1)
        ->and($result['sender_notified'])->toBeTrue();

    $download = RecipientAccessLink::withoutOrganizationScope()
        ->where('purpose', AccessLinkPurpose::Download->value)
        ->firstOrFail();

    expect($download->revoked_at)->toBeNull()
        ->and($download->expires_at->isAfter(now()->addDays(29)))->toBeTrue();

    // O link de assinatura anterior não é reaproveitado como download.
    expect(RecipientAccessLink::withoutOrganizationScope()
        ->where('purpose', AccessLinkPurpose::Signing->value)
        ->whereNull('revoked_at')
        ->count())->toBe(1);

    $attempt = DeliveryAttempt::withoutOrganizationScope()
        ->where('purpose', DeliveryPurpose::Completed->value)
        ->where('to_address', 'maria@exemplo.com')
        ->firstOrFail();

    expect($attempt->status)->toBe(DeliveryStatus::Sent);

    expect($this->provider->to($this->owner->email))->toHaveCount(1);
});

test('sem certificado da operadora o texto fala em aceite eletrônico, nunca em assinatura digital', function () {
    $envelope = completedEnvelope();

    app(CompletionNotifier::class)->notify($envelope);

    $body = $this->provider->lastTo('maria@exemplo.com')->htmlBody;

    expect($body)->toContain('evidências do aceite eletrônico')
        ->and($body)->not->toContain('assinatura criptográfica')
        ->and($body)->not->toContain('ICP-Brasil');
});

test('com certificado da operadora o texto identifica a operadora, não o participante', function () {
    $envelope = completedEnvelope();

    VerificationRecord::factory()->forEnvelope($envelope)->signedByCompany()->create();

    app(CompletionNotifier::class)->notify($envelope->fresh());

    $body = $this->provider->lastTo('maria@exemplo.com')->htmlBody;

    expect($body)->toContain('assinatura criptográfica da AssinaVelox')
        ->and($body)->toContain('identifica a operadora')
        ->and($body)->not->toContain('ICP-Brasil');
});

test('avisar a conclusão é idempotente', function () {
    $envelope = completedEnvelope();

    $notifier = app(CompletionNotifier::class);

    $notifier->notify($envelope);
    $before = $this->provider->count();

    $second = $notifier->notify($envelope->fresh());

    expect($second['notified'])->toBe(0)
        ->and($this->provider->count())->toBe($before);
});

test('envelope que ainda não está concluído não dispara aviso de conclusão', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);

    $result = app(CompletionNotifier::class)->notify($envelope->fresh());

    expect($result['notified'])->toBe(0)
        ->and($result['sender_notified'])->toBeFalse();
});

test('o link de download resolve e é distinto do link de assinatura', function () {
    $envelope = completedEnvelope();

    app(CompletionNotifier::class)->notify($envelope);

    $bodies = $this->provider->lastTo('maria@exemplo.com')->htmlBody;

    preg_match('#/assinar/([A-Za-z0-9_-]{43})#', $bodies, $matches);

    $resolved = app(AccessLinks::class)->resolve($matches[1]);

    expect($resolved?->purpose)->toBe(AccessLinkPurpose::Download)
        ->and($resolved?->isUsable())->toBeTrue();
});
