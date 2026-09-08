<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\FolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $parent_id
 * @property string $name
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Folder extends Model
{
    /** @use HasFactory<FolderFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'parent_id', 'name', 'created_by_user_id'];

    /** @return BelongsTo<Folder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    /** @return HasMany<Folder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    /** @return HasMany<Envelope, $this> */
    public function envelopes(): HasMany
    {
        return $this->hasMany(Envelope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }
}
