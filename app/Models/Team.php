<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Time da organização: agrupa memberships para receber acesso a pastas de uma vez.
 * Não concede permissões de conta (essas vêm só da função).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $name
 * @property string|null $description
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Team extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'name', 'description', 'created_by_user_id'];

    /** @return BelongsToMany<Membership, $this> */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'team_memberships', 'team_id', 'membership_id')
            ->withTimestamps();
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
}
