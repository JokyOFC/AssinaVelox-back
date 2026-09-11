<?php

namespace App\Models;

use App\Enums\FolderAccessLevel;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Support\Permissions;
use App\Support\PermissionsFolderAccess;
use App\Support\PermissionsSystemRoles;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Não usa o escopo de organização: o seletor de organizações lista memberships do usuário
 * em todas as organizações.
 *
 * Papel efetivo (Fase 2 — docs/fase-2/permissoes-e-times.md): `role` (enum) continua
 * sendo o papel de sistema; `role_id` aponta SÓ para uma função personalizada da mesma
 * organização (nesse caso `role` = member). Owner é sempre owner, qualquer que seja o
 * role_id. As permissões resolvidas ficam memorizadas na instância (uma requisição).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property MembershipRole $role
 * @property int|null $role_id
 * @property MembershipStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User $user
 * @property-read Role|null $assignedRole
 */
class Membership extends Pivot
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $table = 'memberships';

    public $incrementing = true;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'user_id', 'role', 'role_id', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => MembershipStatus::Active->value,
    ];

    /** @var list<Permission>|null */
    protected ?array $permissionMemo = null;

    /** @var array<int, FolderAccessLevel>|null */
    protected ?array $folderLevelMemo = null;

    protected int|false|null $effectiveRoleIdMemo = null;

    protected ?bool $customRolesMemo = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => MembershipStatus::class,
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Função personalizada atribuída (sem o escopo da organização corrente: a FK é gravada
     * pelo serviço e `customRole()` confere a organização).
     *
     * @return BelongsTo<Role, $this>
     */
    public function assignedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id')->withoutGlobalScopes();
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_memberships', 'membership_id', 'team_id')
            ->withoutGlobalScopes()
            ->withTimestamps();
    }

    /** @return HasMany<FolderPermission, $this> */
    public function folderPermissions(): HasMany
    {
        // Chave explícita: num Pivot o Eloquent não deduz a FK do nome da classe.
        return $this->hasMany(FolderPermission::class, 'membership_id')->withoutGlobalScopes();
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function isOwner(): bool
    {
        return $this->role === MembershipRole::Owner;
    }

    /**
     * A função personalizada válida desta membership, ou null (papel de sistema).
     *
     * Com a flag `custom_roles` desligada (interruptor global ou plano rebaixado) a função
     * gravada em `role_id` não vale: a pessoa volta a ser o papel de sistema (`role`, que é
     * member), exatamente como na Fase 1 (roadmap §1 T1/T8).
     */
    public function customRole(): ?Role
    {
        if ($this->role_id === null || $this->isOwner() || ! $this->customRolesEnabled()) {
            return null;
        }

        $role = $this->assignedRole;

        if ($role === null || $role->is_system || $role->organization_id !== $this->organization_id) {
            return null;
        }

        return $role;
    }

    /**
     * Flag `custom_roles` da organização desta membership, memorizada na instância (uma
     * requisição).
     */
    public function customRolesEnabled(): bool
    {
        return $this->customRolesMemo ??= Permissions::customRolesEnabled($this->organization);
    }

    public function hasCustomRole(): bool
    {
        return $this->customRole() !== null;
    }

    public function roleLabel(): string
    {
        return $this->customRole()->name ?? $this->role->label();
    }

    /**
     * Permissões da FUNÇÃO, independentemente do status (usadas para comparar poderes —
     * ex.: quem pode gerir esta pessoa).
     *
     * @return list<Permission>
     */
    public function rolePermissions(): array
    {
        return $this->permissionMemo ??= ($this->customRole()?->grantedPermissions()
            ?? Permission::systemGrants($this->role));
    }

    /**
     * Permissões efetivas: nenhuma se a membership não estiver ativa.
     *
     * @return list<Permission>
     */
    public function grantedPermissions(): array
    {
        return $this->isActive() ? $this->rolePermissions() : [];
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->grantedPermissions(), true);
    }

    /**
     * Id da linha em `roles` que representa o papel efetivo (alvo de acesso por pasta
     * concedido a uma função). Pode ser null numa organização sem papéis de sistema
     * gravados (factories de teste).
     */
    public function effectiveRoleId(): ?int
    {
        if ($this->effectiveRoleIdMemo === null) {
            $id = $this->customRole()?->getKey()
                ?? PermissionsSystemRoles::roleFor($this->organization_id, $this->role)?->getKey();

            $this->effectiveRoleIdMemo = $id === null ? false : (int) $id;
        }

        return $this->effectiveRoleIdMemo === false ? null : $this->effectiveRoleIdMemo;
    }

    /**
     * Nível de acesso por pasta (maior entre direto, por função e por time), por folder_id.
     *
     * @return array<int, FolderAccessLevel>
     */
    public function folderLevels(): array
    {
        return $this->folderLevelMemo ??= PermissionsFolderAccess::levelsFor($this);
    }

    /**
     * Descarta o que foi memorizado (após mudar função, times ou acesso por pasta na
     * mesma requisição).
     */
    public function forgetPermissions(): void
    {
        $this->permissionMemo = null;
        $this->folderLevelMemo = null;
        $this->effectiveRoleIdMemo = null;
        $this->customRolesMemo = null;
        $this->unsetRelation('assignedRole');
    }
}
