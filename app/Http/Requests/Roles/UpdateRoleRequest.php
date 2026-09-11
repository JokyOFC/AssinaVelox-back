<?php

namespace App\Http\Requests\Roles;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Validation\Rule;

/**
 * PATCH /usuarios/funcoes/{role}: campos opcionais (`name`, `description`, `permissions[]`).
 * Papéis de sistema não são editáveis (RolePolicy::update). Anti-escalada igual à criação.
 */
class UpdateRoleRequest extends StoreRoleRequest
{
    public function authorize(): bool
    {
        $this->ensureFeature();

        $role = $this->route('role');

        return $role instanceof Role && ($this->user()?->can('update', $role) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }

        if ($this->has('permissions') && is_array($this->input('permissions'))) {
            $this->merge(['permissions' => array_values($this->input('permissions'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:80', $this->uniqueName($role instanceof Role ? $role : null)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array', 'max:'.count(Permission::cases())],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::grantableValues())],
        ];
    }

    public function hasPermissionsInput(): bool
    {
        return array_key_exists('permissions', $this->validated());
    }
}
