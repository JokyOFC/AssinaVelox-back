<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma permissão (App\Enums\Permission) concedida a uma função personalizada. Escrita só
 * por Role::syncPermissions().
 *
 * @property int $id
 * @property int $role_id
 * @property string $permission
 * @property Carbon|null $created_at
 */
class RolePermission extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['role_id', 'permission'];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScopes();
    }
}
