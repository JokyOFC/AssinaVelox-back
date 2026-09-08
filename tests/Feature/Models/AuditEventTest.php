<?php

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\User;

it('grava eventos com ator, payload e occurred_at', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->create();

    $event = AuditEvent::factory()
        ->forEnvelope($envelope)
        ->byUser($user, '203.0.113.10')
        ->ofType(AuditEventType::EnvelopeSent, ['recipients' => 2])
        ->create();

    $event = $event->fresh();

    expect($event->actor_type)->toBe(ActorType::User)
        ->and($event->actor_id)->toBe($user->id)
        ->and($event->actorUser->is($user))->toBeTrue()
        ->and($event->event_type)->toBe(AuditEventType::EnvelopeSent)
        ->and($event->payload)->toBe(['recipients' => 2])
        ->and($event->ip_address)->toBe('203.0.113.10')
        ->and($event->occurred_at)->not->toBeNull()
        ->and($event->kind())->toBe('info')
        ->and($event->organization_id)->toBe($envelope->organization_id);
});

it('é append-only: recusa update e delete pelo model', function () {
    $event = AuditEvent::factory()->create();

    expect(fn () => $event->update(['payload' => ['alterado' => true]]))->toThrow(LogicException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);

    expect(AuditEvent::query()->whereKey($event->id)->exists())->toBeTrue()
        ->and($event->fresh()->payload)->toBe([]);
});

it('não possui updated_at', function () {
    $event = AuditEvent::factory()->create();

    expect(AuditEvent::UPDATED_AT)->toBeNull()
        ->and(array_key_exists('updated_at', $event->getAttributes()))->toBeFalse();
});

it('registra eventos de destinatário com recipient_id preenchido', function () {
    $recipient = Recipient::factory()->create();

    $event = AuditEvent::factory()->byRecipient($recipient)->ofType(AuditEventType::InvitationOpened)->create();

    expect($event->recipient_id)->toBe($recipient->id)
        ->and($event->envelope_id)->toBe($recipient->envelope_id)
        ->and($event->actor_type)->toBe(ActorType::Recipient)
        ->and($recipient->auditEvents()->count())->toBe(1)
        ->and($recipient->envelope->auditEvents()->count())->toBe(1);
});
