<?php

namespace App\Http\Requests\Roles;

use App\Enums\Permission;
use App\Http\Requests\Roles\Concerns\ResolvesAccessActor;
use App\Models\Role;
use App\Support\Permissions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /usuarios/funcoes: `name`, `description?`, `permissions[]` (catálogo delegável).
 * Anti-escalada: só entra permissão que o ator já tem.
 */
class StoreRoleRequest extends FormRequest
{
    use ResolvesAccessActor;

    public function authorize(): bool
    {
        $this->ensureFeature();

        return $this->user()?->can('create', Role::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'permissions' => is_array($this->input('permissions')) ? array_values($this->input('permissions')) : $this->input('permissions', []),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80', $this->uniqueName()],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array', 'max:'.count(Permission::cases())],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::grantableValues())],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkEscalation($validator)];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'description' => 'descrição', 'permissions' => 'permissões', 'permissions.*' => 'permissão'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.*.in' => 'Permissão inválida ou exclusiva do proprietário.',
        ];
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return Permissions::parse((array) $this->validated('permissions', []));
    }

    protected function checkEscalation(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty() || ($actor = $this->actor()) === null) {
            return;
        }

        $missing = array_filter($this->permissions(), fn (Permission $p): bool => ! $actor->hasPermission($p));

        if ($missing !== []) {
            $validator->errors()->add('permissions', 'Você não pode conceder permissões que não tem: '
                .implode(', ', array_map(fn (Permission $p): string => $p->label(), $missing)).'.');
        }
    }

    protected function uniqueName(?Role $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $organization = $this->organization();

            if ($organization === null || ! is_string($value)) {
                return;
            }

            $taken = Role::forOrganization($organization)
                ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->get(['id', 'name'])
                ->contains(fn (Role $role): bool => Str::lower($role->name) === Str::lower($value));

            if ($taken) {
                $fail('Já existe uma função com esse nome.');
            }
        };
    }
}
