<?php

namespace App\Services\Envelopes\Steps;

use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Models\SigningStep;
use Illuminate\Support\Collection;

/**
 * Valida a definição de etapas contra o envelope (docs/fase-3/etapas-e-delegacao.md §2.3).
 *
 * Além do esquema fechado de {@see StepCondition}:
 *
 * - cada participante (signatário, testemunha, aprovador) fica em exatamente UMA etapa; o
 *   visualizador não entra em etapa (recebe o documento no envio, como sempre);
 * - toda etapa tem pelo menos um participante; a primeira nunca tem condição;
 * - a regra de decisão só aponta para um APROVADOR de uma etapa ANTERIOR;
 * - a regra de campo só aponta para caixa de seleção ou texto preenchido por alguém de uma
 *   etapa ANTERIOR. Referência para a mesma etapa ou para frente é recusada: a condição é
 *   avaliada quando a etapa começa, e só o passado já existe.
 */
final class StepDefinitionValidator
{
    /** Tipos de campo que uma condição pode ler. O editor de campos não tem "lista" hoje. */
    public const CONDITION_FIELD_TYPES = [FieldType::Checkbox, FieldType::Text];

    /**
     * @return list<array{index: int, name: string|null, recipients: list<Recipient>, condition: StepCondition|null}>
     *
     * @throws InvalidStepDefinition
     */
    public function validate(Envelope $envelope, mixed $steps): array
    {
        if (! is_array($steps) || ! array_is_list($steps) || $steps === []) {
            throw new InvalidStepDefinition('steps', 'Defina pelo menos uma etapa.');
        }

        $max = FlowFeatures::maxSteps();

        if (count($steps) > $max) {
            throw new InvalidStepDefinition('steps', sprintf('Um documento aceita no máximo %d etapas.', $max));
        }

        /** @var Collection<int, Recipient> $recipients */
        $recipients = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $byUlid = $recipients->keyBy('ulid');

        /** @var array<string, int> $assigned */
        $assigned = [];
        $plan = [];

        foreach ($steps as $offset => $step) {
            $index = $offset + 1;
            $path = 'steps.'.$offset;

            if (! is_array($step) || array_diff(array_keys($step), ['name', 'recipients', 'condition']) !== []) {
                throw new InvalidStepDefinition($path, sprintf('A etapa %d tem um formato inválido.', $index));
            }

            $members = [];
            $ulids = $step['recipients'] ?? null;

            if (! is_array($ulids) || ! array_is_list($ulids) || $ulids === []) {
                throw new InvalidStepDefinition($path.'.recipients', sprintf('A etapa %d precisa de pelo menos um participante.', $index));
            }

            foreach ($ulids as $ulid) {
                $recipient = is_string($ulid) ? $byUlid->get($ulid) : null;

                if (! $recipient instanceof Recipient) {
                    throw new InvalidStepDefinition($path.'.recipients', 'Um dos participantes escolhidos não pertence a este documento.');
                }

                if (! $recipient->participates()) {
                    throw new InvalidStepDefinition($path.'.recipients', 'Visualizadores não entram em etapas: eles recebem o documento já no envio.');
                }

                if (isset($assigned[$recipient->ulid])) {
                    throw new InvalidStepDefinition($path.'.recipients', sprintf('"%s" já está em outra etapa. Cada participante fica em uma única etapa.', $recipient->name));
                }

                $assigned[$recipient->ulid] = $index;
                $members[] = $recipient;
            }

            $plan[] = [
                'index' => $index,
                'name' => self::name($step['name'] ?? null, $path),
                'recipients' => $members,
                'condition' => $step['condition'] ?? null,
            ];
        }

        foreach ($recipients as $recipient) {
            if ($recipient->participates() && ! isset($assigned[$recipient->ulid])) {
                throw new InvalidStepDefinition('steps', sprintf('Coloque "%s" em uma das etapas.', $recipient->name));
            }
        }

        return $this->conditions($envelope, $plan, $byUlid, $assigned);
    }

    /**
     * A definição GRAVADA ainda vale para o envelope como ele está agora? (revalidada no envio,
     * sob o mesmo lock: participantes e campos podem ter mudado depois que as etapas foram salvas).
     *
     * @return list<string> problemas em PT-BR (vazio = pode enviar)
     */
    public function storedIssues(Envelope $envelope): array
    {
        $steps = SigningStep::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('step_index')
            ->get();

        if ($steps->isEmpty()) {
            return ['O fluxo por etapas está ligado, mas nenhuma etapa foi definida. Revise as etapas antes de enviar.'];
        }

        $members = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('role', RecipientRole::participatingValues())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Recipient $recipient): int => (int) $recipient->getAttribute('signing_step_index'));

        $payload = $steps->map(fn (SigningStep $step): array => [
            'name' => $step->name,
            'recipients' => ($members->get($step->step_index) ?? collect())->pluck('ulid')->values()->all(),
            'condition' => $step->condition,
        ])->values()->all();

        try {
            $this->validate($envelope, $payload);
        } catch (InvalidStepDefinition $exception) {
            return [$exception->getMessage()];
        }

        return [];
    }

    /**
     * @param  list<array{index: int, name: string|null, recipients: list<Recipient>, condition: mixed}>  $plan
     * @param  Collection<string, Recipient>  $byUlid
     * @param  array<string, int>  $assigned
     * @return list<array{index: int, name: string|null, recipients: list<Recipient>, condition: StepCondition|null}>
     *
     * @throws InvalidStepDefinition
     */
    private function conditions(Envelope $envelope, array $plan, Collection $byUlid, array $assigned): array
    {
        $fields = SigningField::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->get()
            ->keyBy('ulid');

        $byId = $byUlid->keyBy(fn (Recipient $recipient): int => (int) $recipient->getKey());
        $validated = [];

        foreach ($plan as $offset => $row) {
            $raw = $row['condition'];
            $path = 'steps.'.$offset.'.condition';

            if ($raw === null) {
                $validated[] = [...$row, 'condition' => null];

                continue;
            }

            if ($row['index'] === 1) {
                throw new InvalidStepDefinition($path, 'A primeira etapa não pode ter condição: ela sempre começa no envio.');
            }

            $condition = StepCondition::fromArray($raw, $path);

            foreach ($condition->rules as $ruleOffset => $rule) {
                $rulePath = $path.'.rules.'.$ruleOffset;

                if ($rule['type'] === StepCondition::RULE_APPROVER_DECISION) {
                    $approver = $byUlid->get($rule['recipient']);

                    if (! $approver instanceof Recipient) {
                        throw new InvalidStepDefinition($rulePath.'.recipient', 'O aprovador escolhido não participa deste documento.');
                    }

                    if ($approver->role !== RecipientRole::Approver) {
                        throw new InvalidStepDefinition($rulePath.'.recipient', sprintf('"%s" não é aprovador: a regra de decisão só vale para aprovadores.', $approver->name));
                    }

                    if (($assigned[$approver->ulid] ?? PHP_INT_MAX) >= $row['index']) {
                        throw new InvalidStepDefinition($rulePath.'.recipient', 'A condição só pode usar a decisão de um aprovador de uma etapa anterior.');
                    }

                    continue;
                }

                $field = $fields->get($rule['field']);

                if (! $field instanceof SigningField) {
                    throw new InvalidStepDefinition($rulePath.'.field', 'O campo escolhido não existe neste documento.');
                }

                if (! in_array($field->type, self::CONDITION_FIELD_TYPES, true)) {
                    throw new InvalidStepDefinition($rulePath.'.field', 'Condições usam só campos de caixa de seleção ou de texto.');
                }

                $owner = $byId->get((int) $field->recipient_id);

                if (! $owner instanceof Recipient || ($assigned[$owner->ulid] ?? PHP_INT_MAX) >= $row['index']) {
                    throw new InvalidStepDefinition($rulePath.'.field', 'A condição só pode usar um campo preenchido em uma etapa anterior.');
                }

                if ($field->type === FieldType::Checkbox) {
                    if ($rule['operator'] === StepCondition::OPERATOR_CONTAINS) {
                        throw new InvalidStepDefinition($rulePath.'.operator', 'Para caixa de seleção, use "é igual a" ou "é diferente de".');
                    }

                    if (! in_array($rule['value'], [StepCondition::CHECKBOX_CHECKED, StepCondition::CHECKBOX_UNCHECKED], true)) {
                        throw new InvalidStepDefinition($rulePath.'.value', 'Para caixa de seleção, compare com "marcada" ou "desmarcada".');
                    }
                }
            }

            $validated[] = [...$row, 'condition' => $condition];
        }

        return $validated;
    }

    private static function name(mixed $raw, string $path): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (! is_string($raw)) {
            throw new InvalidStepDefinition($path.'.name', 'O nome da etapa precisa ser um texto.');
        }

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw));

        if (mb_strlen($name) > 80) {
            throw new InvalidStepDefinition($path.'.name', 'O nome da etapa aceita no máximo 80 caracteres.');
        }

        return $name === '' ? null : $name;
    }
}
