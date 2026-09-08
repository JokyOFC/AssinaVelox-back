<?php

use App\Enums\RecipientStatus;
use App\Exceptions\InvalidRecipientTransition;
use App\Models\Envelope;
use App\Models\Recipient;
use Illuminate\Database\QueryException;

it('não permite o mesmo e-mail duas vezes no mesmo envelope', function () {
    $envelope = Envelope::factory()->create();
    Recipient::factory()->forEnvelope($envelope)->create(['email' => 'ana@exemplo.com.br']);

    expect(fn () => Recipient::factory()->forEnvelope($envelope, 2)->create(['email' => 'ana@exemplo.com.br']))
        ->toThrow(QueryException::class);
});

it('permite o mesmo e-mail em envelopes diferentes', function () {
    Recipient::factory()->create(['email' => 'ana@exemplo.com.br']);
    Recipient::factory()->create(['email' => 'ana@exemplo.com.br']);

    expect(Recipient::query()->where('email', 'ana@exemplo.com.br')->count())->toBe(2);
});

it('transitionTo respeita a máquina de estados do destinatário', function () {
    $recipient = Recipient::factory()->create();

    $recipient->transitionTo(RecipientStatus::Notified);
    $recipient->transitionTo(RecipientStatus::Viewed);
    $recipient->transitionTo(RecipientStatus::Signed);

    expect($recipient->status)->toBe(RecipientStatus::Signed)
        ->and($recipient->signed_at)->not->toBeNull()
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Pending);

    expect(fn () => $recipient->transitionTo(RecipientStatus::Refused))
        ->toThrow(InvalidRecipientTransition::class);

    $pending = Recipient::factory()->create();
    expect(fn () => $pending->transitionTo(RecipientStatus::Signed))
        ->toThrow(InvalidRecipientTransition::class);

    $pending->transitionTo(RecipientStatus::Canceled);
    expect($pending->status)->toBe(RecipientStatus::Canceled);
});

it('mascara o e-mail e calcula iniciais', function () {
    $recipient = Recipient::factory()->make(['name' => 'Ana Beatriz Rocha', 'email' => 'ana.rocha@exemplo.com.br']);

    expect($recipient->masked_email)->toBe('a********@exemplo.com.br')
        ->and($recipient->initials)->toBe('AR')
        ->and(Recipient::maskEmail('ab@x.io'))->toBe('a***@x.io');
});
