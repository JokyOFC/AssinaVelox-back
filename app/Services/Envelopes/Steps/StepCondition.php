<?php

namespace App\Services\Envelopes\Steps;

/**
 * Condição de uma etapa — esquema FECHADO (docs/fase-3/etapas-e-delegacao.md §2.2).
 *
 * ```json
 * {"match": "all|any", "rules": [
 *   {"type": "approver_decision", "recipient": "<ULID do aprovador>", "equals": "approved|refused"},
 *   {"type": "field_value", "field": "<ULID do campo>", "operator": "equals|not_equals|contains", "value": "<literal>"}
 * ]}
 * ```
 *
 * Nada além disso é aceito: chave desconhecida, tipo de regra desconhecido, operador fora da
 * lista ou valor que não seja texto curto recusam a condição inteira. Não existe expressão,
 * `eval`, regex, fórmula nem referência a código — o motor só compara decisões e textos
 * literais ({@see ConditionEvaluator}). As referências (aprovador e campo de etapas
 * ANTERIORES) são conferidas contra o envelope por {@see StepDefinitionValidator}.
 */
final class StepCondition
{
    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    public const RULE_APPROVER_DECISION = 'approver_decision';

    public const RULE_FIELD_VALUE = 'field_value';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REFUSED = 'refused';

    public const OPERATOR_EQUALS = 'equals';

    public const OPERATOR_NOT_EQUALS = 'not_equals';

    public const OPERATOR_CONTAINS = 'contains';

    /** Valores literais de uma caixa de seleção. */
    public const CHECKBOX_CHECKED = 'checked';

    public const CHECKBOX_UNCHECKED = 'unchecked';

    private const ULID = '/^[0-9A-Za-z]{26}$/';

    /**
     * @param  list<array<string, string>>  $rules
     */
    private function __construct(
        public readonly string $match,
        public readonly array $rules,
    ) {}

    /**
     * @throws InvalidStepDefinition
     */
    public static function fromArray(mixed $raw, string $path = 'condition'): self
    {
        if (! is_array($raw)) {
            throw new InvalidStepDefinition($path, 'A condição da etapa é inválida.');
        }

        $keys = array_keys($raw);
        sort($keys);

        if ($keys !== ['match', 'rules']) {
            throw new InvalidStepDefinition($path, 'A condição aceita só a forma de combinar ("todas" ou "qualquer") e a lista de regras.');
        }

        $match = $raw['match'];

        if (! in_array($match, [self::MATCH_ALL, self::MATCH_ANY], true)) {
            throw new InvalidStepDefinition($path.'.match', 'Escolha se valem todas as regras ou qualquer uma delas.');
        }

        $rules = $raw['rules'];

        if (! is_array($rules) || ! array_is_list($rules) || $rules === []) {
            throw new InvalidStepDefinition($path.'.rules', 'Inclua pelo menos uma regra na condição.');
        }

        $max = FlowFeatures::maxRules();

        if (count($rules) > $max) {
            throw new InvalidStepDefinition($path.'.rules', sprintf('Uma condição aceita no máximo %d regras.', $max));
        }

        $normalized = [];

        foreach ($rules as $index => $rule) {
            $normalized[] = self::rule($rule, $path.'.rules.'.$index);
        }

        return new self($match, $normalized);
    }

    /**
     * @return array<string, string>
     *
     * @throws InvalidStepDefinition
     */
    private static function rule(mixed $rule, string $path): array
    {
        if (! is_array($rule) || ! is_string($rule['type'] ?? null)) {
            throw new InvalidStepDefinition($path, 'Regra inválida.');
        }

        $keys = array_keys($rule);
        sort($keys);

        return match ($rule['type']) {
            self::RULE_APPROVER_DECISION => self::approverRule($rule, $keys, $path),
            self::RULE_FIELD_VALUE => self::fieldRule($rule, $keys, $path),
            default => throw new InvalidStepDefinition($path.'.type', 'Tipo de regra não permitido. Use a decisão de um aprovador ou o valor de um campo.'),
        };
    }

    /**
     * @param  array<mixed>  $rule
     * @param  list<int|string>  $keys
     * @return array<string, string>
     */
    private static function approverRule(array $rule, array $keys, string $path): array
    {
        if ($keys !== ['equals', 'recipient', 'type']) {
            throw new InvalidStepDefinition($path, 'A regra de decisão aceita só o aprovador e a decisão esperada.');
        }

        if (! is_string($rule['recipient']) || preg_match(self::ULID, $rule['recipient']) !== 1) {
            throw new InvalidStepDefinition($path.'.recipient', 'Escolha o aprovador da regra.');
        }

        if (! in_array($rule['equals'], [self::DECISION_APPROVED, self::DECISION_REFUSED], true)) {
            throw new InvalidStepDefinition($path.'.equals', 'A decisão esperada precisa ser "aprovou" ou "recusou".');
        }

        return ['type' => self::RULE_APPROVER_DECISION, 'recipient' => $rule['recipient'], 'equals' => $rule['equals']];
    }

    /**
     * @param  array<mixed>  $rule
     * @param  list<int|string>  $keys
     * @return array<string, string>
     */
    private static function fieldRule(array $rule, array $keys, string $path): array
    {
        if ($keys !== ['field', 'operator', 'type', 'value']) {
            throw new InvalidStepDefinition($path, 'A regra de campo aceita só o campo, a comparação e o valor.');
        }

        if (! is_string($rule['field']) || preg_match(self::ULID, $rule['field']) !== 1) {
            throw new InvalidStepDefinition($path.'.field', 'Escolha o campo da regra.');
        }

        if (! in_array($rule['operator'], [self::OPERATOR_EQUALS, self::OPERATOR_NOT_EQUALS, self::OPERATOR_CONTAINS], true)) {
            throw new InvalidStepDefinition($path.'.operator', 'Compare com "é igual a", "é diferente de" ou "contém".');
        }

        if (! is_string($rule['value'])) {
            throw new InvalidStepDefinition($path.'.value', 'Informe o valor a comparar.');
        }

        // Literal de texto e só isso: sem caracteres de controle, com tamanho limitado.
        $value = trim($rule['value']);
        $max = FlowFeatures::maxLiteralLength();

        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value) !== 0) {
            throw new InvalidStepDefinition($path.'.value', sprintf('Informe um valor de 1 a %d caracteres, sem quebras de linha.', $max));
        }

        return ['type' => self::RULE_FIELD_VALUE, 'field' => $rule['field'], 'operator' => $rule['operator'], 'value' => $value];
    }

    /**
     * @return array{match: string, rules: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return ['match' => $this->match, 'rules' => $this->rules];
    }

    /**
     * @return list<string>
     */
    public function recipientUlids(): array
    {
        $ulids = [];

        foreach ($this->rules as $rule) {
            if ($rule['type'] === self::RULE_APPROVER_DECISION) {
                $ulids[] = $rule['recipient'];
            }
        }

        return array_values(array_unique($ulids));
    }

    /**
     * @return list<string>
     */
    public function fieldUlids(): array
    {
        $ulids = [];

        foreach ($this->rules as $rule) {
            if ($rule['type'] === self::RULE_FIELD_VALUE) {
                $ulids[] = $rule['field'];
            }
        }

        return array_values(array_unique($ulids));
    }
}
