<?php

namespace App\Http\Requests\Members;

use App\Http\Requests\Members\Concerns\ResolvesTargetRole;
use App\Models\Membership;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /usuarios/{membership}: `role ∈ admin | member` (papel de sistema) OU `role_id`
 * (ulid de uma função personalizada da organização, com a flag `custom_roles`). Owner só
 * via transferência. Anti-escalada: o ator precisa ter todas as permissões da função.
 */
class UpdateMembershipRoleRequest extends FormRequest
{
    use ResolvesTargetRole;

    public function authorize(): bool
    {
        $membership = $this->route('membership');

        return $membership instanceof Membership && ($this->user()?->can('update', $membership) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required_without:role_id', 'nullable', 'string', Rule::in(self::ASSIGNABLE_SYSTEM_ROLES)],
            'role_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->resolveTargetRole($validator)];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['role' => 'função', 'role_id' => 'função'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'A função deve ser Administrador ou Operador. Para definir um proprietário, use "Transferir propriedade".',
            'role.required_without' => 'Escolha uma função.',
        ];
    }
}
