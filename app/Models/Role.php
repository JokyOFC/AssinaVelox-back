<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Função (papel) de uma organização — docs/fase-2/permissoes-e-times.md.
 *
 * Papéis de sistema (`is_system`): owner/admin/member, criados para toda organização,
 * não editáveis nem removíveis; permissões vindas do código (Permission::systemGrants).
 * Funções personalizadas: permissões em `role_permissions`, sempre dentro do catálogo
 * delegável (Permission::isGrantable).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string|null $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'key', 'name', 'description', 'is_system', 'created_by_user_id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_system' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /** @return HasMany<RolePermission, $this> */
    public function permissionRows(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * Memberships com esta função personalizada (papéis de sistema não usam role_id).
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<FolderPermission, $this> */
    public function folderPermissions(): HasMany
    {
        return $this->hasMany(FolderPermission::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function systemRole(): ?MembershipRole
    {
        return $this->is_system && $this->key !== null ? MembershipRole::tryFrom($this->key) : null;
    }

    /**
     * Permissões que a função concede. Papel de sistema → código; personalizada →
     * `role_permissions`, descartando valores desconhecidos ou não delegáveis (defesa
     * contra uma linha gravada fora do serviço).
     *
     * @return list<Permission>
     */
    public function grantedPermissions(): array
    {
        $system = $this->systemRole();

        if ($system !== null) {
            return Permission::systemGrants($system);
        }

        if ($this->is_system) {
            return [];
        }

        $granted = [];

        foreach ($this->permissionRows as $row) {
            $permission = Permission::tryFrom($row->permission);

            if ($permission !== null && $permission->isGrantable()) {
                $granted[$permission->value] = $permission;
            }
        }

        // Ordem do catálogo, estável para comparação e para a UI.
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $p): bool => isset($granted[$p->value]),
        ));
    }

    /**
     * @return list<string>
     */
    public function grantedPermissionValues(): array
    {
        return array_map(fn (Permission $p): string => $p->value, $this->grantedPermissions());
    }

    /**
     * Substitui o conjunto concedido (só funções personalizadas).
     *
     * @param  list<Permission>  $permissions
     */
    public function syncPermissions(array $permissions): void
    {
        if ($this->is_system) {
            return;
        }

        $values = array_values(array_unique(array_map(
            fn (Permission $p): string => $p->value,
            array_filter($permissions, fn (Permission $p): bool => $p->isGrantable()),
        )));

        DB::transaction(function () use ($values): void {
            RolePermission::query()->where('role_id', $this->getKey())->whereNotIn('permission', $values)->delete();

            $existing = RolePermission::query()->where('role_id', $this->getKey())->pluck('permission')->all();
            $now = Carbon::now();

            $rows = array_map(fn (string $value): array => [
                'role_id' => $this->getKey(),
                'permission' => $value,
                'created_at' => $now,
            ], array_values(array_diff($values, $existing)));

            if ($rows !== []) {
                RolePermission::query()->insert($rows);
            }
        });

        $this->unsetRelation('permissionRows');
    }
}
