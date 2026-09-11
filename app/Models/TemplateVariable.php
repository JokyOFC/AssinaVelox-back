<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Templates\VariableType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Variável tipada de uma versão de modelo. Imutável junto com a versão.
 *
 * `options` por tipo (docs/fase-2/modelos.md §3):
 *  - text/long_text: {max_length}
 *  - number: {min, max, decimals}
 *  - currency: {min_cents, max_cents}
 *  - date: {min, max} (Y-m-d)
 *  - select: {choices: list<string>}
 *
 * @property int $id
 * @property string $ulid
 * @property int $template_version_id
 * @property int $organization_id
 * @property string $key
 * @property string $label
 * @property VariableType $type
 * @property bool $required
 * @property string|null $help_text
 * @property string|null $default_value
 * @property array<string, mixed>|null $options
 * @property int $position
 */
class TemplateVariable extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'template_version_id',
        'organization_id',
        'key',
        'label',
        'type',
        'required',
        'help_text',
        'default_value',
        'options',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VariableType::class,
            'required' => 'boolean',
            'options' => 'array',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Variáveis de modelo pertencem a uma versão imutável.');
        });
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }
}
