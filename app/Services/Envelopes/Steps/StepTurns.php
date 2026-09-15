<?php

namespace App\Services\Envelopes\Steps;

use App\Enums\SigningOrder;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningStep;
use Illuminate\Support\Collection;

/**
 * A VEZ (`recipients.order_index`) de um envelope com etapas (Fase 3 §3.3, F-FLOW).
 *
 * Com etapas, `current_order` continua sendo a vez corrente — o núcleo (convite, lembrete,
 * reenvio, lote, presencial, página pública) segue comparando `order_index` com ela — e a vez
 * passa a ser derivada da etapa de cada participante:
 *
 * - `sequential`: uma vez por participante, na ordem (etapa, posição na lista, id). Cada etapa
 *   é um trecho contínuo de vezes.
 * - `parallel`: a vez É a etapa — todos os participantes da etapa assinam juntos.
 *
 * O visualizador continua sem vez (0) e fora das etapas. Participante sem etapa (adicionado
 * depois de as etapas serem salvas) vai para a ÚLTIMA etapa, e o envio revalida tudo.
 * Sem etapas (`uses_signing_steps` falso) nada é tocado.
 */
final class StepTurns
{
    /**
     * @return int quantidade de destinatários alterados
     */
    public static function apply(Envelope $envelope): int
    {
        if (! $envelope->usesSigningSteps()) {
            return 0;
        }

        $lastStep = (int) SigningStep::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->max('step_index');

        $lastStep = max(1, $lastStep);

        /** @var Collection<int, Recipient> $recipients */
        $recipients = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $changed = 0;
        $participants = [];

        foreach ($recipients as $recipient) {
            if (! $recipient->participates()) {
                $changed += self::write($recipient, 0, null);

                continue;
            }

            $step = (int) ($recipient->getAttribute('signing_step_index') ?? 0);
            $participants[] = ['recipient' => $recipient, 'step' => $step >= 1 && $step <= $lastStep ? $step : $lastStep];
        }

        // Ordenação estável: etapa, depois a ordem da lista (a coleção já vem por posição).
        usort($participants, static fn (array $a, array $b): int => $a['step'] <=> $b['step']);

        $turn = 0;

        foreach ($participants as $row) {
            $turn++;
            $order = $envelope->signing_order === SigningOrder::Sequential ? $turn : $row['step'];

            $changed += self::write($row['recipient'], $order, $row['step']);
        }

        return $changed;
    }

    /**
     * As vezes gravadas são as que {@see self::apply()} gravaria? É a invariante de prontidão
     * de um envelope com etapas (no paralelo, a vez é a etapa — diferente do "todos em 1").
     */
    public static function isCoherent(Envelope $envelope): bool
    {
        $recipients = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $participants = [];

        foreach ($recipients as $recipient) {
            if (! $recipient->participates()) {
                if ((int) $recipient->order_index !== 0) {
                    return false;
                }

                continue;
            }

            $step = $recipient->getAttribute('signing_step_index');

            if ($step === null || (int) $step < 1) {
                return false;
            }

            $participants[] = ['recipient' => $recipient, 'step' => (int) $step];
        }

        usort($participants, static fn (array $a, array $b): int => $a['step'] <=> $b['step']);

        $turn = 0;

        foreach ($participants as $row) {
            $turn++;
            $expected = $envelope->signing_order === SigningOrder::Sequential ? $turn : $row['step'];

            if ((int) $row['recipient']->order_index !== $expected) {
                return false;
            }
        }

        return true;
    }

    private static function write(Recipient $recipient, int $order, ?int $step): int
    {
        $currentStep = $recipient->getAttribute('signing_step_index');
        $currentStep = $currentStep === null ? null : (int) $currentStep;

        if ((int) $recipient->order_index === $order && $currentStep === $step) {
            return 0;
        }

        $recipient->forceFill(['order_index' => $order, 'signing_step_index' => $step])->save();

        return 1;
    }
}
