<?php

use App\Enums\RecipientStatus;

it('segue pending → notified → viewed → signed | refused e qualquer não-terminal → canceled | expired', function () {
    $matrix = [
        'pending' => ['notified', 'canceled', 'expired'],
        'notified' => ['viewed', 'canceled', 'expired'],
        'viewed' => ['signed', 'refused', 'canceled', 'expired'],
        'signed' => [],
        'refused' => [],
        'expired' => [],
        'canceled' => [],
    ];

    foreach ($matrix as $from => $allowed) {
        $fromStatus = RecipientStatus::from($from);

        foreach (RecipientStatus::cases() as $to) {
            expect($fromStatus->canTransitionTo($to))
                ->toBe(in_array($to->value, $allowed, true), "{$from} → {$to->value}");
        }
    }
});

it('identifica estados terminais e pendentes', function () {
    expect(RecipientStatus::Signed->isTerminal())->toBeTrue()
        ->and(RecipientStatus::Refused->isTerminal())->toBeTrue()
        ->and(RecipientStatus::Expired->isTerminal())->toBeTrue()
        ->and(RecipientStatus::Canceled->isTerminal())->toBeTrue()
        ->and(RecipientStatus::Pending->isPendingSignature())->toBeTrue()
        ->and(RecipientStatus::Notified->isPendingSignature())->toBeTrue()
        ->and(RecipientStatus::Viewed->isPendingSignature())->toBeTrue()
        ->and(RecipientStatus::Signed->isPendingSignature())->toBeFalse();
});

it('tem rótulos e notas em português', function () {
    expect(RecipientStatus::Pending->label())->toBe('Pendente')
        ->and(RecipientStatus::Pending->note())->toBe('Aguarda a vez')
        ->and(RecipientStatus::Notified->note())->toBe('Enviado · não visualizou')
        ->and(RecipientStatus::Signed->label())->toBe('Assinado')
        ->and(RecipientStatus::Refused->badgeVariant())->toBe('danger')
        ->and(RecipientStatus::Signed->badgeVariant())->toBe('success')
        ->and(RecipientStatus::Notified->badgeVariant())->toBe('warning')
        ->and(RecipientStatus::Canceled->badgeVariant())->toBe('neutral');
});
