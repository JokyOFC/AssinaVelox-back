<?php

namespace App\Http\Requests\Settings;

use App\Services\Organizations\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /configuracoes/notificacoes (ROUTES §2.14): preferences: Record<NotificationEvent, NotificationChannel[]>.
 * Qualquer membro ativo pode salvar as PRÓPRIAS preferências (middleware `org`).
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = ['preferences' => ['required', 'array']];

        foreach (NotificationPreferences::events() as $event) {
            // Eventos condicionais só vêm quando a linha aparece na tela.
            $rules["preferences.{$event}"] = in_array($event, NotificationPreferences::CONDITIONAL, true)
                ? ['sometimes', 'array']
                : ['present', 'array'];
            $rules["preferences.{$event}.*"] = ['string', Rule::in(NotificationPreferences::CHANNELS)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = ['preferences' => 'preferências'];

        foreach (NotificationPreferences::catalog() as $event => $definition) {
            $attributes["preferences.{$event}"] = $definition['label'];
            $attributes["preferences.{$event}.*"] = 'canal de "'.$definition['label'].'"';
        }

        return $attributes;
    }

    /**
     * @return array<string, list<string>>
     */
    public function preferences(): array
    {
        return array_map(fn ($channels): array => array_values((array) $channels), $this->validated('preferences'));
    }
}
