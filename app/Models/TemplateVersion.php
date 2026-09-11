<?php

namespace App\Models;

use App\Enums\RecipientRole;
use App\Enums\SigningOrder;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Templates\TemplateSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Versão IMUTÁVEL de um modelo: arquivo de origem (ou HTML sanitizado), variáveis, papéis e
 * campos. Editar o modelo cria outra versão; nenhuma linha desta tabela (nem das filhas) é
 * alterada depois de criada — é isso que garante que envelopes já gerados não mudam.
 *
 * @property int $id
 * @property string $ulid
 * @property int $template_id
 * @property int $organization_id
 * @property int $version_number
 * @property TemplateSourceType $source_type
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property string|null $html_body
 * @property int|null $page_count
 * @property array<int, array<string, mixed>>|null $pages_meta
 * @property array<string, mixed>|null $settings
 * @property string $definition_hash
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 */
class TemplateVersion extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'template_id',
        'organization_id',
        'version_number',
        'source_type',
        'storage_disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'sha256',
        'html_body',
        'page_count',
        'pages_meta',
        'settings',
        'definition_hash',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'source_type' => TemplateSourceType::class,
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'pages_meta' => 'array',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Versões de modelo são imutáveis: grave uma versão nova.');
        });
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return HasMany<TemplateVariable, $this> */
    public function variables(): HasMany
    {
        return $this->hasMany(TemplateVariable::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<TemplateRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(TemplateRole::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<TemplateField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class)->orderBy('page')->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Metadados da página (1-based) no formato de `document_versions.pages_meta`.
     *
     * @return array<string, mixed>|null
     */
    public function pageMeta(int $page): ?array
    {
        return $this->pages_meta[$page - 1] ?? null;
    }

    public function signingOrder(): ?SigningOrder
    {
        return SigningOrder::tryFrom((string) ($this->settings['signing_order'] ?? ''));
    }

    public function hasFile(): bool
    {
        return $this->storage_path !== null && $this->storage_disk !== null;
    }

    /**
     * Algum papel exige a flag `participant_roles` (testemunha, aprovador, visualizador)?
     */
    public function usesNonSignerRoles(): bool
    {
        return $this->roles->contains(fn (TemplateRole $role): bool => $role->participant_role !== RecipientRole::Signer);
    }
}
