<?php

namespace App\Http\Requests\Settings;

use App\Rules\CpfOrCnpj;
use App\Support\CurrentOrganization;
use App\Support\Timezones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /configuracoes/empresa (ROUTES §2.12): legal_name, name, tax_id, contact_email, timezone.
 */
class UpdateOrganizationSettingsRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'tax_id' => ['nullable', 'string', 'max:20', new CpfOrCnpj],
            'contact_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'timezone' => ['sometimes', 'nullable', 'string', Rule::in(Timezones::identifiers())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome de exibição',
            'legal_name' => 'razão social',
            'tax_id' => 'CPF/CNPJ',
            'contact_email' => 'e-mail de contato',
            'timezone' => 'fuso horário',
        ];
    }
}
