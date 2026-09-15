<?php

namespace App\Services\Envelopes\Steps;

use App\Enums\AuditEventType;
use App\Enums\RecipientRole;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningStep;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\PreparationGuard;
use App\Services\Envelopes\RecipientSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava a definição de etapas do wizard (PUT `envelopes.steps.update`). Só em preparo
 * (`draft|preparing|ready`), sob o lock de preparo: depois do envio a definição está
 * congelada, como os participantes e os campos.
 *
 * `enabled = false` desfaz tudo: apaga as etapas, limpa a etapa dos participantes e reaplica a
 * vez padrão do `signing_order` (RecipientSync::reindex) — o envelope volta a ser o de antes.
 */
final class SigningStepPlan
{
    public function __construct(private readonly StepDefinitionValidator $validator) {}

    /**
     * @throws ValidationException
     */
    public function update(Envelope $envelope, bool $enabled, mixed $steps): void
    {
        DB::transaction(function () use ($envelope, $enabled, $steps): void {
            $locked = PreparationGuard::lockForPreparation($envelope, 'steps');

            if (! $enabled) {
                $had = $locked->usesSigningSteps();

                SigningStep::withoutOrganizationScope()->where('envelope_id', $locked->getKey())->delete();
                Recipient::withoutOrganizationScope()
                    ->where('envelope_id', $locked->getKey())
                    ->update(['signing_step_index' => null]);

                $locked->forceFill(['uses_signing_steps' => false])->save();

                RecipientSync::reindex($locked);

                if ($had) {
                    EnvelopeAudit::record($locked, AuditEventType::SigningStepsUpdated, ['enabled' => false]);
                }

                return;
            }

            try {
                $plan = $this->validator->validate($locked, $steps);
            } catch (InvalidStepDefinition $exception) {
                throw $exception->toValidationException();
            }

            SigningStep::withoutOrganizationScope()->where('envelope_id', $locked->getKey())->delete();

            $conditional = 0;
            $rules = 0;

            foreach ($plan as $row) {
                $step = new SigningStep;
                $step->forceFill([
                    'organization_id' => $locked->organization_id,
                    'envelope_id' => $locked->getKey(),
                    'step_index' => $row['index'],
                    'name' => $row['name'],
                    'condition' => $row['condition']?->toArray(),
                    'status' => SigningStep::STATUS_PENDING,
                ])->save();

                if ($row['condition'] !== null) {
                    $conditional++;
                    $rules += count($row['condition']->rules);
                }

                Recipient::withoutOrganizationScope()
                    ->whereIn('id', array_map(static fn (Recipient $recipient): int => (int) $recipient->getKey(), $row['recipients']))
                    ->update(['signing_step_index' => $row['index']]);
            }

            Recipient::withoutOrganizationScope()
                ->where('envelope_id', $locked->getKey())
                ->where('role', RecipientRole::Viewer->value)
                ->update(['signing_step_index' => null]);

            $locked->forceFill(['uses_signing_steps' => true, 'current_order' => 1])->save();

            StepTurns::apply($locked);

            EnvelopeAudit::record($locked, AuditEventType::SigningStepsUpdated, [
                'enabled' => true,
                'steps' => count($plan),
                'conditional_steps' => $conditional,
                'rules' => $rules,
            ]);
        });

        $envelope->refresh();
    }
}
