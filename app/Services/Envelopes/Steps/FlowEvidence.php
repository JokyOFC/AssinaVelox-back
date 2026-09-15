<?php

namespace App\Services\Envelopes\Steps;

use App\Enums\RecipientStatus;
use App\Models\Delegation;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningStep;
use App\Services\Envelopes\Delegation\DelegationPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Etapas e delegações na PÁGINA DE EVIDÊNCIAS (docs/fase-3/etapas-e-delegacao.md §4): quem
 * delegou a quem, quando, com que confirmação e por quê; que etapas se aplicaram ou foram
 * puladas, com a regra e os valores avaliados. Envelope sem etapas e sem delegações: `null`, e
 * a página é exatamente a de antes.
 *
 * Os textos citados (motivo, valor de campo) são dados do participante: saem entre aspas e são
 * escapados pelo Blade — nunca interpretados.
 */
final class FlowEvidence
{
    /**
     * @return array{steps: list<array<string, mixed>>, delegations: list<array<string, mixed>>}|null
     */
    public static function forEnvelope(Envelope $envelope, string $timezone): ?array
    {
        $steps = $envelope->usesSigningSteps()
            ? SigningStep::withoutOrganizationScope()->where('envelope_id', $envelope->getKey())->orderBy('step_index')->get()
            : collect();

        $delegations = Delegation::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('status', [Delegation::STATUS_EFFECTIVE, Delegation::STATUS_REJECTED])
            ->orderBy('id')
            ->get();

        if ($steps->isEmpty() && $delegations->isEmpty()) {
            return null;
        }

        $showIp = (string) $envelope->organization->setting('evidence_show_ip', config('assinavelox.evidence_show_ip', 'masked')) !== 'none';
        $names = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->get()
            ->keyBy(fn (Recipient $recipient): int => (int) $recipient->getKey());

        return [
            'steps' => array_values($steps->map(fn (SigningStep $step): array => [
                'index' => $step->step_index,
                'name' => $step->name,
                'status' => $step->status,
                'status_label' => $step->statusLabel(),
                'evaluated_at' => self::local($step->evaluated_at, $timezone),
                'has_condition' => $step->condition !== null,
                'rules' => self::describeEvaluation($step->evaluation),
            ])->all()),
            'delegations' => array_values($delegations->map(function (Delegation $delegation) use ($names, $timezone, $showIp): array {
                $from = $names->get((int) $delegation->from_recipient_id);

                return [
                    'status' => $delegation->status,
                    'status_label' => $delegation->statusLabel(),
                    'from_name' => $from->name ?? '—',
                    'from_email' => $from->email ?? '—',
                    'to_name' => $delegation->to_name,
                    'to_email' => $delegation->to_email,
                    'reason' => Str::limit($delegation->reason, 500),
                    'requested_at' => self::local($delegation->requested_at, $timezone),
                    'delegated_at' => self::local($delegation->delegated_at, $timezone),
                    'confirmed_at' => self::local($delegation->approved_by_sender_at, $timezone),
                    'rejected_at' => self::local($delegation->rejected_at, $timezone),
                    'ip' => $showIp ? $delegation->ip_address : null,
                    'user_agent' => $delegation->user_agent,
                ];
            })->all()),
        ];
    }

    /**
     * Nota por participante (chave: id interno do destinatário) para a tabela de participantes.
     *
     * @return array<int, array{flow_note: string}>
     */
    public static function participantNotes(Envelope $envelope, string $timezone): array
    {
        $recipients = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->get()
            ->keyBy(fn (Recipient $recipient): int => (int) $recipient->getKey());

        $notes = [];

        foreach (Delegation::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('status', Delegation::STATUS_EFFECTIVE)
            ->orderBy('id')
            ->get() as $delegation) {
            $from = $recipients->get((int) $delegation->from_recipient_id);

            $notes[(int) $delegation->from_recipient_id] = ['flow_note' => sprintf(
                'Delegou a %s em %s%s. Motivo: “%s”.',
                $delegation->to_name,
                self::local($delegation->delegated_at, $timezone) ?? '—',
                $delegation->approved_by_sender_at !== null ? ', com confirmação de quem enviou' : '',
                Str::limit($delegation->reason, 300),
            )];

            if ($delegation->to_recipient_id !== null) {
                $notes[(int) $delegation->to_recipient_id] = ['flow_note' => sprintf(
                    'Participou por delegação de %s, com aceite próprio.',
                    $from->name ?? '—',
                )];
            }
        }

        foreach ($recipients as $recipient) {
            if ($recipient->status === RecipientStatus::Canceled
                && $recipient->getAttribute('status_reason') === StepProgression::REASON_STEP_SKIPPED) {
                $notes[(int) $recipient->getKey()] = ['flow_note' => sprintf(
                    'Não participou: a etapa %d não se aplicou.',
                    (int) $recipient->getAttribute('signing_step_index'),
                )];
            }

            if ($recipient->status === RecipientStatus::Delegated && ! isset($notes[(int) $recipient->getKey()])
                && $recipient->getAttribute('status_reason') === DelegationPolicy::REASON_DELEGATED) {
                $notes[(int) $recipient->getKey()] = ['flow_note' => 'Delegou a participação a outra pessoa.'];
            }
        }

        return $notes;
    }

    /**
     * Frases da avaliação gravada ("Decisão de Paula: esperado aprovou, registrado recusou — falsa").
     *
     * @param  array<string, mixed>|null  $evaluation
     * @return list<string>
     */
    public static function describeEvaluation(?array $evaluation): array
    {
        if ($evaluation === null || ! is_array($evaluation['rules'] ?? null) || $evaluation['rules'] === []) {
            return [];
        }

        $lines = [];

        foreach ($evaluation['rules'] as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $verdict = ($rule['result'] ?? false) === true ? 'verdadeira' : 'falsa';

            if (($rule['type'] ?? null) === StepCondition::RULE_APPROVER_DECISION) {
                $lines[] = sprintf(
                    'Decisão de %s: esperado "%s", registrado "%s" — %s',
                    is_string($rule['decided_by'] ?? null) ? $rule['decided_by'] : 'aprovador',
                    self::decision($rule['expected'] ?? null),
                    self::decision($rule['observed'] ?? null),
                    $verdict,
                );

                continue;
            }

            $lines[] = sprintf(
                'Campo "%s" %s "%s"; valor registrado "%s" — %s',
                is_string($rule['label'] ?? null) && $rule['label'] !== '' ? $rule['label'] : 'sem rótulo',
                match ($rule['operator'] ?? null) {
                    StepCondition::OPERATOR_NOT_EQUALS => 'diferente de',
                    StepCondition::OPERATOR_CONTAINS => 'contém',
                    default => 'igual a',
                },
                self::literal($rule['expected'] ?? null),
                self::literal($rule['observed'] ?? null),
                $verdict,
            );
        }

        $match = ($evaluation['match'] ?? null) === StepCondition::MATCH_ANY ? 'qualquer regra' : 'todas as regras';
        $lines[] = sprintf('Combinação: %s — condição %s.', $match, ($evaluation['result'] ?? false) === true ? 'verdadeira' : 'falsa');

        return $lines;
    }

    private static function decision(mixed $value): string
    {
        return match ($value) {
            StepCondition::DECISION_APPROVED => 'aprovou',
            StepCondition::DECISION_REFUSED => 'recusou',
            default => 'sem decisão',
        };
    }

    private static function literal(mixed $value): string
    {
        return match ($value) {
            StepCondition::CHECKBOX_CHECKED => 'marcada',
            StepCondition::CHECKBOX_UNCHECKED => 'desmarcada',
            null => '—',
            default => Str::limit((string) $value, ConditionEvaluator::OBSERVED_LIMIT),
        };
    }

    private static function local(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->format('d/m/Y H:i:s');
    }
}
