<?php

namespace App\Services\Envelopes\Steps;

use App\Enums\FieldType;
use Illuminate\Support\Str;

/**
 * Motor de regras DECLARATIVO e fechado: compara uma decisão com "aprovou/recusou" e o valor
 * gravado de um campo com um literal. Nada é interpretado, executado ou avaliado como
 * expressão — o valor do campo é dado não confiável (T6) e só é comparado como texto.
 *
 * Comparação de texto: sem diferenciar maiúsculas/minúsculas e com os espaços normalizados
 * ("  Sim " = "sim"). `contains` é substring literal (nunca regex). Caixa de seleção vale
 * `checked`/`unchecked`; sem valor gravado conta como desmarcada. Aprovador que ainda não
 * decidiu (ou cuja etapa foi pulada) não satisfaz nem "aprovou" nem "recusou".
 */
final class ConditionEvaluator
{
    /** Tamanho máximo do valor observado copiado para a evidência e a trilha. */
    public const OBSERVED_LIMIT = 120;

    /**
     * @return array{result: bool, match: string, rules: list<array<string, mixed>>}
     */
    public function evaluate(StepCondition $condition, EvaluationFacts $facts): array
    {
        $rows = [];

        foreach ($condition->rules as $rule) {
            $rows[] = $rule['type'] === StepCondition::RULE_APPROVER_DECISION
                ? $this->approver($rule, $facts)
                : $this->field($rule, $facts);
        }

        $results = array_map(static fn (array $row): bool => $row['result'] === true, $rows);

        $result = $condition->match === StepCondition::MATCH_ALL
            ? ! in_array(false, $results, true)
            : in_array(true, $results, true);

        return ['result' => $result, 'match' => $condition->match, 'rules' => $rows];
    }

    /**
     * @param  array<string, string>  $rule
     * @return array<string, mixed>
     */
    private function approver(array $rule, EvaluationFacts $facts): array
    {
        $observed = $facts->decisionOf($rule['recipient']);

        return [
            'type' => StepCondition::RULE_APPROVER_DECISION,
            'recipient' => $rule['recipient'],
            'decided_by' => $facts->deciderName($rule['recipient']),
            'expected' => $rule['equals'],
            'observed' => $observed ?? 'none',
            'result' => $observed !== null && $observed === $rule['equals'],
        ];
    }

    /**
     * @param  array<string, string>  $rule
     * @return array<string, mixed>
     */
    private function field(array $rule, EvaluationFacts $facts): array
    {
        $field = $facts->fieldOf($rule['field']);
        $row = [
            'type' => StepCondition::RULE_FIELD_VALUE,
            'field' => $rule['field'],
            'label' => $field['label'] ?? null,
            'operator' => $rule['operator'],
            'expected' => $rule['value'],
        ];

        if ($field === null) {
            return $row + ['observed' => null, 'result' => false];
        }

        if ($field['type'] === FieldType::Checkbox->value) {
            $observed = $field['bool'] === true ? StepCondition::CHECKBOX_CHECKED : StepCondition::CHECKBOX_UNCHECKED;

            $result = match ($rule['operator']) {
                StepCondition::OPERATOR_EQUALS => $observed === $rule['value'],
                StepCondition::OPERATOR_NOT_EQUALS => $observed !== $rule['value'],
                default => false,
            };

            return $row + ['observed' => $observed, 'result' => $result];
        }

        $text = (string) ($field['text'] ?? '');
        $observed = self::normalize($text);
        $expected = self::normalize($rule['value']);

        $result = match ($rule['operator']) {
            StepCondition::OPERATOR_EQUALS => $observed === $expected,
            StepCondition::OPERATOR_NOT_EQUALS => $observed !== $expected,
            StepCondition::OPERATOR_CONTAINS => $expected !== '' && str_contains($observed, $expected),
            default => false,
        };

        return $row + ['observed' => Str::limit($text, self::OBSERVED_LIMIT), 'result' => $result];
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
