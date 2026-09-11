<?php

namespace App\Support;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Plan;

/**
 * API de autorização por permissão (Fase 2 — docs/fase-2/permissoes-e-times.md).
 *
 * Quem decide é sempre o backend (Policies); o front consome `organization.permissions`
 * e nunca decide por papel. Este arquivo concentra:
 *  - resolução das permissões efetivas de uma membership (`has`, `for`, `covers`);
 *  - o mapa compartilhado `organization.permissions` (`sharedMap`);
 *  - a flag `custom_roles` (funções personalizadas, times e acesso por pasta);
 *  - a matriz/descrições exibidas na aba "Funções e permissões";
 *  - a tradução das rotas protegidas por `org.role:*` para permissões (`routeAllows`).
 */
final class Permissions
{
    /**
     * Chaves de `organization.permissions` na Fase 1 (mantidas por compatibilidade).
     *
     * @var list<string>
     */
    public const KEYS = Permission::LEGACY_SHARED_KEYS;

    /**
     * Rotas hoje protegidas por `org.role:*` → permissão equivalente. Nomes exatos têm
     * precedência sobre os prefixos. Para papéis de sistema o resultado é idêntico ao
     * `org.role` da Fase 1 (ver PermissionsRoutesTest).
     *
     * @var array<string, Permission>
     */
    private const ROUTE_PERMISSIONS = [
        'members.transfer_ownership' => Permission::TransferOwnership,
        'settings.organization.destroy' => Permission::DeleteOrganization,
        'billing.cancel' => Permission::DeleteOrganization,
        'recipients.resend_pending' => Permission::ManageAnyEnvelope,
        'integrations.keys' => Permission::ManageIntegrations,
        'integrations.logs' => Permission::ManageIntegrations,
        'plans.index' => Permission::ManageBilling,
        'settings.general' => Permission::ManageSettings,
        'settings.organization.update' => Permission::ManageSettings,
        'settings.security.update' => Permission::ManageSettings,
        'settings.signing' => Permission::ManageSettings,
        'settings.signing.update' => Permission::ManageSettings,
    ];

    /** @var array<string, Permission> */
    private const ROUTE_PREFIX_PERMISSIONS = [
        'members.' => Permission::ManageMembers,
        'invitations.' => Permission::ManageMembers,
        'folders.' => Permission::ManageFolders,
        'billing.' => Permission::ManageBilling,
    ];

    // -- Resolução ------------------------------------------------------------------------

    /**
     * @return list<Permission>
     */
    public static function for(Membership $membership): array
    {
        return $membership->grantedPermissions();
    }

    public static function has(Membership $membership, Permission $permission): bool
    {
        return $membership->hasPermission($permission);
    }

    public static function hasAny(Membership $membership, Permission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($membership->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O ator tem TODAS as permissões informadas? Base da regra anti-escalada: ninguém
     * concede (a uma função, a um convite, a outra pessoa) o que não tem.
     *
     * @param  iterable<Permission>  $permissions
     */
    public static function covers(Membership $actor, iterable $permissions): bool
    {
        $held = $actor->grantedPermissions();

        foreach ($permissions as $permission) {
            if (! in_array($permission, $held, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * O ator tem pelo menos os poderes da função do alvo? (admin não gere owner; uma função
     * com "Convidar e remover usuários" não gere quem pode mais do que ela.)
     */
    public static function coversMembership(Membership $actor, Membership $target): bool
    {
        return self::covers($actor, $target->rolePermissions());
    }

    /**
     * Converte valores vindos do cliente em permissões DELEGÁVEIS (desconhecidas e
     * exclusivas do proprietário são descartadas — a validação já as rejeita antes).
     *
     * @param  array<int, mixed>  $values
     * @return list<Permission>
     */
    public static function parse(array $values): array
    {
        $parsed = [];

        foreach ($values as $value) {
            $permission = is_string($value) ? Permission::tryFrom($value) : null;

            if ($permission !== null && $permission->isGrantable()) {
                $parsed[$permission->value] = $permission;
            }
        }

        return array_values($parsed);
    }

    // -- Props compartilhadas ---------------------------------------------------------------

    /**
     * Mapa legado (as 7 chaves da Fase 1) de um papel de sistema.
     *
     * @return array<string, bool>
     */
    public static function forRole(?MembershipRole $role): array
    {
        $granted = $role === null ? [] : Permission::systemGrants($role);

        $map = [];

        foreach (self::KEYS as $key) {
            $map[$key] = in_array(Permission::from($key), $granted, true);
        }

        return $map;
    }

    /**
     * `organization.permissions`: as 7 chaves da Fase 1 SEMPRE (agora derivadas da função
     * efetiva) e, com `custom_roles` ligada para a organização, o catálogo completo.
     *
     * @return array<string, bool>
     */
    public static function sharedMap(Membership $membership, ?Organization $organization = null, ?Plan $plan = null): array
    {
        $granted = $membership->grantedPermissions();
        $map = [];

        foreach (self::KEYS as $key) {
            $map[$key] = in_array(Permission::from($key), $granted, true);
        }

        $organization ??= $membership->organization;

        if (self::customRolesEnabled($organization, $plan)) {
            foreach (Permission::cases() as $permission) {
                $map[$permission->value] = in_array($permission, $granted, true);
            }
        }

        return $map;
    }

    /**
     * Flag `custom_roles` (funções personalizadas, times e acesso por pasta). Desligada por
     * padrão. O interruptor global `config('assinavelox.features.custom_roles')` é
     * OBRIGATÓRIO (roadmap §1 T8: desligado, nenhum plano liga o recurso). Com ele ligado,
     * `plans.features.custom_roles` (booleano) decide por plano; sem a chave no plano,
     * vale o interruptor global. A flag só liga a interface e os cadastros — a autorização
     * continua nas Policies.
     */
    public static function customRolesEnabled(?Organization $organization, ?Plan $plan = null): bool
    {
        if ($organization === null || config('assinavelox.features.custom_roles', false) !== true) {
            return false;
        }

        $plan ??= $organization->currentSubscription()->with('plan')->first()?->plan;
        $flag = is_array($plan?->features) ? ($plan->features['custom_roles'] ?? null) : null;

        return is_bool($flag) ? $flag : true;
    }

    /**
     * Cadastros de funções personalizadas, times e acesso por pasta só existem com a flag.
     * Desligada: 403 com mensagem clara (nada é criado nem alterado).
     */
    public static function ensureCustomRoles(?Organization $organization): void
    {
        abort_unless(
            self::customRolesEnabled($organization),
            403,
            'Funções personalizadas, times e acesso por pasta não estão disponíveis no plano desta conta.',
        );
    }

    // -- Catálogo e matriz -------------------------------------------------------------------

    /**
     * Catálogo agrupado para a UI.
     *
     * @return list<array{key: string, label: string, permissions: list<array{key: string, label: string, description: string, grantable: bool}>}>
     */
    public static function catalog(): array
    {
        $byGroup = [];

        foreach (Permission::cases() as $permission) {
            $byGroup[$permission->group()][] = [
                'key' => $permission->value,
                'label' => $permission->label(),
                'description' => $permission->description(),
                'grantable' => $permission->isGrantable(),
            ];
        }

        $catalog = [];

        foreach (Permission::groupLabels() as $key => $label) {
            if (isset($byGroup[$key])) {
                $catalog[] = ['key' => $key, 'label' => $label, 'permissions' => $byGroup[$key]];
            }
        }

        return $catalog;
    }

    /**
     * Matriz dos papéis de sistema exibida na aba "Funções e permissões" (ROUTES §2.10),
     * derivada do catálogo — a mesma fonte usada pelas Policies.
     *
     * @return array<int, array{key: string, label: string, description: string, grants: array<string, bool>}>
     */
    public static function matrix(): array
    {
        $grants = [];

        foreach (MembershipRole::cases() as $role) {
            $grants[$role->value] = Permission::systemGrants($role);
        }

        return array_map(fn (Permission $permission): array => [
            'key' => $permission->value,
            'label' => $permission->label(),
            'description' => $permission->description(),
            'grants' => array_map(
                fn (array $granted): bool => in_array($permission, $granted, true),
                $grants,
            ),
        ], Permission::cases());
    }

    /**
     * @return array<int, array{key: string, label: string, description: string}>
     */
    public static function roles(): array
    {
        return [
            ['key' => MembershipRole::Owner->value, 'label' => MembershipRole::Owner->label(), 'description' => 'Acesso total, inclusive cobrança, exclusão da conta e gestão de proprietários.'],
            ['key' => MembershipRole::Admin->value, 'label' => MembershipRole::Admin->label(), 'description' => 'Gerencia usuários (exceto proprietários), pastas, todos os documentos e configurações.'],
            ['key' => MembershipRole::Member->value, 'label' => MembershipRole::Member->label(), 'description' => 'Cria e gerencia os próprios documentos; vê apenas os próprios.'],
        ];
    }

    // -- Rotas `org.role` ------------------------------------------------------------------

    public static function requiredForRoute(string $routeName): ?Permission
    {
        if (isset(self::ROUTE_PERMISSIONS[$routeName])) {
            return self::ROUTE_PERMISSIONS[$routeName];
        }

        foreach (self::ROUTE_PREFIX_PERMISSIONS as $prefix => $permission) {
            if (str_starts_with($routeName, $prefix)) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * Decisão equivalente a `org.role:{roles}` ciente de funções personalizadas. Papel de
     * sistema → exatamente a regra da Fase 1 (papel na lista). Função personalizada → a
     * permissão mapeada para a rota; rota não mapeada falha fechada.
     *
     * Integração pendente (fora da área de B-PERM): EnsureMembershipRole deve chamar este
     * método em vez de comparar o enum — ver docs/fase-2/permissoes-e-times.md §7.
     *
     * @param  list<MembershipRole>  $roles
     */
    public static function routeAllows(Membership $membership, array $roles, ?string $routeName): bool
    {
        if (! $membership->hasCustomRole()) {
            return in_array($membership->role, $roles, true);
        }

        $required = $routeName !== null ? self::requiredForRoute($routeName) : null;

        return $required !== null && $membership->hasPermission($required);
    }

    // -- Cache ---------------------------------------------------------------------------

    /**
     * Invalida os contadores cacheados (sidebar/topbar) de TODOS os membros da
     * organização. Chamado depois de qualquer mudança em função, time ou acesso por pasta:
     * a visibilidade muda na hora, sem esperar o TTL.
     */
    public static function forgetCounts(int $organizationId): void
    {
        Membership::query()
            ->where('organization_id', $organizationId)
            ->pluck('user_id')
            ->each(fn ($userId) => HandleInertiaRequests::forgetCounts($organizationId, (int) $userId));
    }
}
