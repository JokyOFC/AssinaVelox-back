<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Templates\TemplateSourceType;
use App\Services\Templates\TemplateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Modelo de documento (Fase 2 §2.1 — docs/fase-2/modelos.md).
 *
 * O cadastro (nome, descrição, categoria, situação) é editável; o conteúdo não: ele vive em
 * {@see TemplateVersion}, imutável. `current_version_id` aponta a versão que "Usar modelo"
 * aplica agora.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property TemplateSourceType $source_type
 * @property TemplateStatus $status
 * @property int|null $current_version_id
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TemplateVersion|null $currentVersion
 */
class Template extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'category',
        'source_type',
        'status',
        'current_version_id',
        'created_by_user_id',
        'updated_by_user_id',
        'archived_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => TemplateSourceType::class,
            'status' => TemplateStatus::class,
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'current_version_id');
    }

    /** @return HasMany<TemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class)->orderByDesc('version_number');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function isArchived(): bool
    {
        return $this->status === TemplateStatus::Archived;
    }

    /**
     * Pode gerar envelopes: ativo e com uma versão.
     */
    public function isUsable(): bool
    {
        return ! $this->isArchived() && $this->current_version_id !== null;
    }
}
