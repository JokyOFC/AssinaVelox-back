<?php

namespace App\Models;

use App\Enums\FolderAccessLevel;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Acesso a UMA pasta concedido a exatamente um sujeito: função (role_id), time (team_id)
 * ou membership (membership_id). Escrito só por App\Support\PermissionsFolderAccess.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $folder_id
 * @property int|null $role_id
 * @property int|null $team_id
 * @property int|null $membership_id
 * @property FolderAccessLevel $level
 * @property int|null $granted_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Folder $folder
 */
class FolderPermission extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'folder_id', 'role_id', 'team_id', 'membership_id', 'level', 'granted_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => FolderAccessLevel::class,
        ];
    }

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
