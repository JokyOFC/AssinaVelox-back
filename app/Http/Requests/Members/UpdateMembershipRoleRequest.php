<?php

namespace App\Http\Requests\Members;

use App\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /usuarios/{membership}: `role ∈ admin | member` (owner só via transferência).
 */
class UpdateMembershipRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('membership')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in([MembershipRole::Admin->value, MembershipRole::Member->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['role' => 'função'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'A função deve ser Administrador ou Operador. Para definir um proprietário, use "Transferir propriedade".',
        ];
    }

    public function role(): MembershipRole
    {
        return MembershipRole::from($this->validated('role'));
    }
}
