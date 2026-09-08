<?php

use App\Enums\EnvelopeStatus;

/**
 * Matriz de transições de docs/arquitetura.md §3.2 (+ draft → ready, ver EnvelopeStatus).
 *
 * @return array<string, array<int, string>>
 */
function envelopeTransitionMatrix(): array
{
    return [
        'draft' => ['preparing', 'ready', 'canceled'],
        'preparing' => ['ready', 'draft', 'canceled'],
        'ready' => ['draft', 'in_progress', 'canceled'],
        'in_progress' => ['finalizing', 'refused', 'expired', 'canceled'],
        'finalizing' => ['completed'],
        'completed' => [],
        'refused' => [],
        'expired' => [],
        'canceled' => [],
    ];
}

it('permite exatamente as transições do contrato de domínio', function () {
    foreach (envelopeTransitionMatrix() as $from => $allowed) {
        $fromStatus = EnvelopeStatus::from($from);

        foreach (EnvelopeStatus::cases() as $to) {
            expect($fromStatus->canTransitionTo($to))
                ->toBe(in_array($to->value, $allowed, true), "{$from} → {$to->value}");
        }
    }
});

it('marca completed, refused, expired e canceled como terminais', function () {
    expect(EnvelopeStatus::Completed->isTerminal())->toBeTrue()
        ->and(EnvelopeStatus::Refused->isTerminal())->toBeTrue()
        ->and(EnvelopeStatus::Expired->isTerminal())->toBeTrue()
        ->and(EnvelopeStatus::Canceled->isTerminal())->toBeTrue()
        ->and(EnvelopeStatus::Draft->isTerminal())->toBeFalse()
        ->and(EnvelopeStatus::InProgress->isTerminal())->toBeFalse()
        ->and(EnvelopeStatus::Finalizing->isTerminal())->toBeFalse();

    expect(EnvelopeStatus::terminal())->toHaveCount(4);
});

it('não permite cancelar durante a finalização', function () {
    expect(EnvelopeStatus::Finalizing->isCancelable())->toBeFalse()
        ->and(EnvelopeStatus::InProgress->isCancelable())->toBeTrue()
        ->and(EnvelopeStatus::Draft->isCancelable())->toBeTrue();
});

it('tem rótulos em português e variantes de badge', function () {
    expect(EnvelopeStatus::Draft->label())->toBe('Rascunho')
        ->and(EnvelopeStatus::Preparing->label())->toBe('Rascunho · processando')
        ->and(EnvelopeStatus::InProgress->label())->toBe('Aguardando')
        ->and(EnvelopeStatus::InProgress->labelWithProgress(1))->toBe('Em andamento')
        ->and(EnvelopeStatus::Finalizing->label())->toBe('Em andamento · finalizando')
        ->and(EnvelopeStatus::Completed->label())->toBe('Assinado')
        ->and(EnvelopeStatus::Refused->label())->toBe('Recusado')
        ->and(EnvelopeStatus::Expired->label())->toBe('Expirado')
        ->and(EnvelopeStatus::Canceled->label())->toBe('Cancelado');

    expect(EnvelopeStatus::InProgress->badgeVariant())->toBe('warning')
        ->and(EnvelopeStatus::InProgress->badgeVariant(2))->toBe('info')
        ->and(EnvelopeStatus::Completed->badgeVariant())->toBe('success')
        ->and(EnvelopeStatus::Refused->badgeVariant())->toBe('danger')
        ->and(EnvelopeStatus::Draft->badgeVariant())->toBe('neutral');

    foreach (EnvelopeStatus::cases() as $status) {
        expect($status->badgeVariant())->toBeIn(['neutral', 'warning', 'info', 'success', 'danger']);
    }
});

it('expõe os valores canônicos', function () {
    expect(EnvelopeStatus::values())->toBe([
        'draft', 'preparing', 'ready', 'in_progress', 'finalizing', 'completed', 'refused', 'expired', 'canceled',
    ]);
});
