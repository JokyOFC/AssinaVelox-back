<?php

namespace App\Services\Retention;

use App\Models\Organization;
use App\Models\RetentionEvent;
use App\Models\RetentionPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Leitura e gravação da política de retenção da organização, com as regras:
 *
 *  1. cada prazo é nulo ("não apagar automaticamente") ou um inteiro entre o MÍNIMO da
 *     operadora ({@see RetentionConfig::minimumDays()}) e {@see RetentionConfig::MAX_DAYS};
 *  2. a trilha de auditoria não sai antes dos documentos que ela prova: se definida, é >= os
 *     prazos de concluídos e de encerrados;
 *  3. a trilha só pode ter prazo se a operadora permitir apagá-la (T7);
 *  4. REDUZIR um prazo (ou passar de "não apagar" para um prazo) e ATIVAR a exclusão
 *     automática exigem a frase {@see RetentionConfig::REDUCTION_CONFIRMATION} — conferida aqui,
 *     no servidor, e não só no diálogo da tela.
 *
 * Mesmo depois de salva, a política nunca é aplicada abaixo do mínimo vigente: se a operadora
 * subir um mínimo, {@see self::effectiveDays()} usa o maior dos dois.
 */
final class RetentionPolicies
{
    public function forOrganization(Organization|int $organization): ?RetentionPolicy
    {
        $id = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        return RetentionPolicy::withoutOrganizationScope()->where('organization_id', $id)->first();
    }

    public static function effectiveDays(RetentionPolicy $policy, RetentionCategory $category): ?int
    {
        $days = $policy->daysFor($category);

        return $days === null ? null : max($days, RetentionConfig::minimumDays($category));
    }

    /**
     * @param  array<string, mixed>  $input  is_active, periods{categoria: int|null}, confirmation
     *
     * @throws ValidationException
     */
    public function save(Organization $organization, User $actor, array $input): RetentionPolicy
    {
        $rules = [
            'is_active' => ['required', 'boolean'],
            'periods' => ['present', 'array'],
            'confirmation' => ['nullable', 'string', 'max:60'],
        ];
        $messages = [];
        $attributes = [];

        foreach (RetentionCategory::cases() as $category) {
            $minimum = RetentionConfig::minimumDays($category);
            $key = 'periods.'.$category->value;

            $rules[$key] = ['nullable', 'integer', 'min:'.$minimum, 'max:'.RetentionConfig::MAX_DAYS];
            $messages[$key.'.min'] = sprintf('O prazo mínimo para “%s” é de %d dias (definido pela operadora).', $category->label(), $minimum);
            $messages[$key.'.max'] = sprintf('O prazo máximo é de %d dias.', RetentionConfig::MAX_DAYS);
            $messages[$key.'.integer'] = 'Informe o prazo em dias, sem casas decimais.';
            $attributes[$key] = $category->label();
        }

        $validated = Validator::make($input, $rules, $messages, $attributes)->validate();

        /** @var array<string, mixed> $raw */
        $raw = is_array($validated['periods'] ?? null) ? $validated['periods'] : [];

        $periods = [];

        foreach (RetentionCategory::cases() as $category) {
            $value = $raw[$category->value] ?? null;
            $periods[$category->value] = $value === null || $value === '' ? null : (int) $value;
        }

        $errors = [];

        if ($periods[RetentionCategory::AuditTrail->value] !== null && ! RetentionConfig::auditTrailDeletionAllowed()) {
            $errors['periods.'.RetentionCategory::AuditTrail->value] = 'Nesta instalação a trilha de auditoria é mantida sem exclusão automática. Deixe o campo em branco.';
        }

        $trail = $periods[RetentionCategory::AuditTrail->value];

        foreach ([RetentionCategory::Completed, RetentionCategory::TerminalOther] as $documents) {
            $days = $periods[$documents->value];

            if ($trail !== null && $days !== null && $trail < $days) {
                $errors['periods.'.RetentionCategory::AuditTrail->value] = sprintf(
                    'A trilha de auditoria não pode sair antes dos documentos que ela prova: use pelo menos %d dias (prazo de “%s”).',
                    $days,
                    $documents->label(),
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $current = $this->forOrganization($organization);
        $before = $current?->periods() ?? array_fill_keys(array_map(fn (RetentionCategory $c): string => $c->value, RetentionCategory::cases()), null);
        $wasActive = $current->is_active ?? false;
        $isActive = (bool) $validated['is_active'];

        $reduced = [];

        foreach ($periods as $key => $value) {
            $old = $before[$key] ?? null;

            if ($value !== null && ($old === null || $value < $old)) {
                $reduced[] = $key;
            }
        }

        $hasAnyPeriod = array_filter($periods, fn (?int $value): bool => $value !== null) !== [];
        $activating = $isActive && ! $wasActive && $hasAnyPeriod;
        $needsConfirmation = $activating || ($isActive && $reduced !== []);

        if ($needsConfirmation && trim((string) ($validated['confirmation'] ?? '')) !== RetentionConfig::REDUCTION_CONFIRMATION) {
            throw ValidationException::withMessages([
                'confirmation' => sprintf('Para reduzir prazos ou ativar a exclusão automática, digite %s.', RetentionConfig::REDUCTION_CONFIRMATION),
            ]);
        }

        $attributesToSave = ['is_active' => $isActive, 'updated_by_user_id' => $actor->getKey()];

        foreach (RetentionCategory::cases() as $category) {
            $attributesToSave[$category->column()] = $periods[$category->value];
        }

        $policy = $current ?? new RetentionPolicy(['organization_id' => $organization->getKey()]);
        $policy->forceFill($attributesToSave + ['organization_id' => $organization->getKey()])->save();

        RetentionTrail::record(
            (int) $organization->getKey(),
            RetentionEvent::POLICY_UPDATED,
            [
                'is_active' => $isActive,
                'was_active' => $wasActive,
                'before' => $before,
                'after' => $periods,
                'reduced' => $reduced,
            ],
            subjectUlid: $policy->ulid,
            actorUserId: (int) $actor->getKey(),
        );

        return $policy->fresh() ?? $policy;
    }
}
