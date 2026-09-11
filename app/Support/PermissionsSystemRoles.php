<?php

namespace App\Support;

use App\Enums\MembershipRole;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Support\Collection;

/**
 * Papéis de sistema por organização (Proprietário, Administrador, Operador). Criados na
 * abertura da organização (CreateOrganization) e, para as existentes, pela migration
 * 2026_09_11_110103. `ensureFor` é idempotente e também serve de rede de segurança.
 */
final class PermissionsSystemRoles
{
    /**
     * @return Collection<string, Role> indexado por key (owner|admin|member)
     */
    public static function ensureFor(Organization|int $organization): Collection
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        $existing = Role::forOrganization($organizationId)
            ->where('is_system', true)
            ->get()
            ->keyBy('key');

        foreach (Permissions::roles() as $definition) {
            if ($existing->has($definition['key'])) {
                continue;
            }

            $name = $definition['label'];

            if (Role::forOrganization($organizationId)->where('name', $name)->exists()) {
                $name .= ' (sistema)';
            }

            $role = new Role;
            $role->forceFill([
                'organization_id' => $organizationId,
                'key' => $definition['key'],
                'name' => $name,
                'description' => $definition['description'],
                'is_system' => true,
            ])->save();

            $existing->put($definition['key'], $role);
        }

        return $existing;
    }

    public static function roleFor(int $organizationId, MembershipRole $role): ?Role
    {
        return Role::forOrganization($organizationId)
            ->where('is_system', true)
            ->where('key', $role->value)
            ->first();
    }
}
