<?php

namespace App\Http\Requests\Organizations;

use App\Rules\CpfOrCnpj;
use App\Support\Timezones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Criar nova organização" (switcher, ROUTES §1.2 organizations.store / Q5).
 */
class StoreOrganizationRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'tax_id' => ['nullable', 'string', 'max:20', new CpfOrCnpj],
            'timezone' => ['nullable', 'string', Rule::in(Timezones::identifiers())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome da organização',
            'legal_name' => 'razão social',
            'tax_id' => 'CPF/CNPJ',
            'timezone' => 'fuso horário',
        ];
    }
}
