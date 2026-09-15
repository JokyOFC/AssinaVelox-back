<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\BulkGeneration\BulkGenerationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lote de geração documental (Fase 3 §3.1 — docs/fase-3/geracao-em-lote.md).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $template_id
 * @property int $template_version_id
 * @property int $template_version_number
 * @property BulkGenerationStatus $status
 * @property string|null $source_file
 * @property string $source_filename
 * @property string $source_format
 * @property string $source_sha256
 * @property int $source_size_bytes
 * @property list<string>|null $headers
 * @property list<array{column: int, target: string}>|null $mapping
 * @property array<string, mixed>|null $options
 * @property int $row_count
 * @property int $valid_count
 * @property int $invalid_count
 * @property int $created_count
 * @property int $failed_count
 * @property int $canceled_count
 * @property int|null $created_by_user_id
 * @property int|null $confirmed_by_user_id
 * @property int|null $canceled_by_user_id
 * @property Carbon|null $dry_run_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $canceled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Template $template
 * @property-read TemplateVersion $templateVersion
 * @property-read User|null $creator
 */
class BulkGeneration extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'template_id',
        'template_version_id',
        'template_version_number',
        'status',
        'source_file',
        'source_filename',
        'source_format',
        'source_sha256',
        'source_size_bytes',
        'headers',
        'mapping',
        'options',
        'row_count',
        'valid_count',
        'invalid_count',
        'created_count',
        'failed_count',
        'canceled_count',
        'created_by_user_id',
        'confirmed_by_user_id',
        'canceled_by_user_id',
        'dry_run_at',
        'confirmed_at',
        'finished_at',
        'canceled_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'row_count' => 0,
        'valid_count' => 0,
        'invalid_count' => 0,
        'created_count' => 0,
        'failed_count' => 0,
        'canceled_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BulkGenerationStatus::class,
            'headers' => 'array',
            'mapping' => 'array',
            'options' => 'array',
            'template_version_number' => 'integer',
            'source_size_bytes' => 'integer',
            'row_count' => 'integer',
            'valid_count' => 'integer',
            'invalid_count' => 'integer',
            'created_count' => 'integer',
            'failed_count' => 'integer',
            'canceled_count' => 'integer',
            'dry_run_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'finished_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class);
    }

    /** @return HasMany<BulkGenerationRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(BulkGenerationRow::class)->orderBy('row_index');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }
}
