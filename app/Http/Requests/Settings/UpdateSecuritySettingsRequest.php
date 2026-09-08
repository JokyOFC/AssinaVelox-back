<?php

namespace App\Http\Requests\Settings;

use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /configuracoes/seguranca (ROUTES §2.12): require_two_factor, session_idle_hours (12|null).
 */
class UpdateSecuritySettingsRequest extends FormRequest
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
        return [
            'require_two_factor' => ['required', 'boolean'],
            'session_idle_hours' => ['present', 'nullable', 'integer', Rule::in([12])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'require_two_factor' => 'exigir autenticação em duas etapas',
            'session_idle_hours' => 'encerrar sessões inativas',
        ];
    }
}
