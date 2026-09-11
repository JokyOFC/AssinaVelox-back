<?php

namespace App\Http\Requests\Settings;

use App\Enums\SigningOrder;
use App\Services\Envelopes\Reminders\ReminderSettings;
use App\Services\Envelopes\Reminders\RemindersFeature;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /configuracoes/assinatura (ROUTES §2.13).
 *
 * `reminders` (Fase 2 §2.5) só é validado — e só é gravado pelo controller — com a flag
 * `features.reminders` ligada. É opcional: o payload da Fase 1, sem a chave, continua válido.
 */
class UpdateSigningSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = CurrentOrganization::instance()->get();

        return $organization !== null && ($this->user()?->can('updateSettings', $organization) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'expires_in_days' => [
                'required',
                'integer',
                'min:'.(int) config('assinavelox.expiration_days.min', 1),
                'max:'.(int) config('assinavelox.expiration_days.max', 90),
            ],
            'signing_order' => ['required', 'string', Rule::enum(SigningOrder::class)],
            'initials_on_all_pages' => ['required', 'boolean'],
            'allow_typed_signature' => ['required', 'boolean'],
            'allow_uploaded_signature' => ['required', 'boolean'],
        ];

        if (! $this->remindersAvailable()) {
            return $rules;
        }

        $rules['reminders'] = ['sometimes', 'array'];

        foreach (ReminderSettings::rules('reminders.') as $key => $keyRules) {
            $rules[$key] = ['required_with:reminders', ...array_values(array_diff($keyRules, ['required']))];
        }

        $rules['reminders.window_start_hour'] = ['required_with:reminders', 'integer', 'min:0', 'max:23'];
        $rules['reminders.window_end_hour'] = ['required_with:reminders', 'integer', 'min:1', 'max:24', 'gt:reminders.window_start_hour'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'expires_in_days' => 'prazo para assinatura',
            'signing_order' => 'ordem de assinatura',
            'initials_on_all_pages' => 'rubrica automática em todas as páginas',
            'allow_typed_signature' => 'permitir assinatura digitada',
            'allow_uploaded_signature' => 'permitir imagem de assinatura',
            ...ReminderSettings::attributes('reminders.'),
            'reminders.window_start_hour' => 'início do horário de envio',
            'reminders.window_end_hour' => 'fim do horário de envio',
        ];
    }

    private function remindersAvailable(): bool
    {
        return app(RemindersFeature::class)->enabledFor(CurrentOrganization::instance()->get());
    }
}
