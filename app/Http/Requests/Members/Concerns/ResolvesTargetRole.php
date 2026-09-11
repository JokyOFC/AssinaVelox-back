<?php

namespace App\Http\Requests\Members\Concerns;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Role;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use Illuminate\Validation\Validator;

/**
 * Resolve a função pedida (papel de sistema por `role` ou função personalizada por
 * `role_id`) e aplica a regra anti-escalada — ninguém atribui (a outra pessoa ou a um
 * convite) uma função com permissões que não tem.
 */
trait ResolvesTargetRole
{
    /** @var list<string> */
    public const ASSIGNABLE_SYSTEM_ROLES = ['admin', 'member'];

    protected ?Role $customTarget = null;

    protected function resolveTargetRole(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $current = CurrentOrganization::instance();
        $organization = $current->get();
        $actor = $current->membership();
        $roleId = $this->input('role_id');

        if (is_string($roleId) && $roleId !== '') {
            $role = $organization === null ? null : Role::forOrganization($organization)->where('ulid', $roleId)->first();

            if ($role === null) {
                $validator->errors()->add('role_id', 'Função não encontrada.');

                return;
            }

            if ($role->is_system) {
                // Ulid de papel de sistema: equivale a `role` = key.
                if (! in_array($role->key, self::ASSIGNABLE_SYSTEM_ROLES, true)) {
                    $validator->errors()->add('role', 'A função deve ser Administrador ou Operador. Para definir um proprietário, use "Transferir propriedade".');

                    return;
                }

                $this->merge(['role' => $role->key, 'role_id' => null]);
            } else {
                if (! Permissions::customRolesEnabled($organization)) {
                    $validator->errors()->add('role_id', 'Funções personalizadas não estão disponíveis no plano desta conta.');

                    return;
                }

                $this->customTarget = $role;
            }
        }

        if ($actor !== null && ! Permissions::covers($actor, $this->targetPermissions())) {
            $validator->errors()->add('role', 'Você não pode atribuir uma função com permissões que você não tem.');
        }
    }

    /**
     * Papel de sistema gravado em `memberships.role` / `membership_invitations.role`
     * (member quando a função é personalizada).
     */
    public function role(): MembershipRole
    {
        if ($this->customTarget !== null) {
            return MembershipRole::Member;
        }

        return MembershipRole::tryFrom((string) $this->input('role')) ?? MembershipRole::Member;
    }

    public function customRole(): ?Role
    {
        return $this->customTarget;
    }

    /**
     * @return list<Permission>
     */
    public function targetPermissions(): array
    {
        return $this->customTarget?->grantedPermissions() ?? Permission::systemGrants($this->role());
    }

    public function targetLabel(): string
    {
        return $this->customTarget->name ?? $this->role()->label();
    }
}
