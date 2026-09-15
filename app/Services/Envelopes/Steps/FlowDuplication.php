<?php

namespace App\Services\Envelopes\Steps;

use App\Models\Envelope;
use App\Models\SigningStep;
use App\Services\Envelopes\Delegation\DelegationPolicy;

/**
 * "Duplicar" um envelope com etapas e/ou regras de delegação (chamado por DuplicateEnvelope).
 *
 * As etapas são copiadas com as referências das condições (ULID do aprovador e do campo)
 * trocadas pelas da cópia, todas `pending`. A lista de participantes "pessoais" da delegação
 * também é remapeada. Referência sem correspondente fica como está e o envio da cópia a recusa
 * (StepDefinitionValidator), em vez de apontar silenciosamente para outra pessoa.
 * Envelope sem etapas e sem regras de delegação: nada muda.
 */
final class FlowDuplication
{
    /**
     * @param  array<string, string>  $recipientUlids  ULID de origem => ULID da cópia
     * @param  array<string, string>  $fieldUlids  ULID de origem => ULID da cópia
     */
    public static function copy(Envelope $source, Envelope $copy, array $recipientUlids, array $fieldUlids): void
    {
        $settings = $copy->settings ?? [];
        $personal = $settings[DelegationPolicy::SETTING_PERSONAL] ?? null;

        if (is_array($personal)) {
            $settings[DelegationPolicy::SETTING_PERSONAL] = array_values(array_unique(array_filter(array_map(
                static fn (mixed $ulid): ?string => is_string($ulid) ? ($recipientUlids[$ulid] ?? null) : null,
                $personal,
            ))));

            $copy->forceFill(['settings' => $settings])->save();
        }

        if (! $source->usesSigningSteps()) {
            return;
        }

        $steps = SigningStep::withoutOrganizationScope()
            ->where('envelope_id', $source->getKey())
            ->orderBy('step_index')
            ->get();

        foreach ($steps as $step) {
            $clone = new SigningStep;
            $clone->forceFill([
                'organization_id' => $copy->organization_id,
                'envelope_id' => $copy->getKey(),
                'step_index' => $step->step_index,
                'name' => $step->name,
                'condition' => self::remap($step->condition, $recipientUlids, $fieldUlids),
                'status' => SigningStep::STATUS_PENDING,
            ])->save();
        }

        $copy->forceFill(['uses_signing_steps' => true])->save();
    }

    /**
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, string>  $recipientUlids
     * @param  array<string, string>  $fieldUlids
     * @return array<string, mixed>|null
     */
    private static function remap(?array $condition, array $recipientUlids, array $fieldUlids): ?array
    {
        if ($condition === null) {
            return null;
        }

        $rules = [];

        foreach ((array) ($condition['rules'] ?? []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if (isset($rule['recipient']) && is_string($rule['recipient'])) {
                $rule['recipient'] = $recipientUlids[$rule['recipient']] ?? $rule['recipient'];
            }

            if (isset($rule['field']) && is_string($rule['field'])) {
                $rule['field'] = $fieldUlids[$rule['field']] ?? $rule['field'];
            }

            $rules[] = $rule;
        }

        return ['match' => $condition['match'] ?? StepCondition::MATCH_ALL, 'rules' => $rules];
    }
}
