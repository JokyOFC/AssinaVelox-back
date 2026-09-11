<?php

namespace App\Models;

use App\Enums\FieldBoxType;
use App\Enums\FieldType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Campo pré-posicionado de um modelo PDF, ligado a um papel. Mesma geometria de
 * {@see SigningField}: frações [0,1] da página EXIBIDA, origem no canto superior esquerdo
 * (docs/campos-e-geometria.md). Imutável junto com a versão.
 *
 * @property int $id
 * @property string $ulid
 * @property int $template_version_id
 * @property int $template_role_id
 * @property int $organization_id
 * @property FieldType $type
 * @property int $page
 * @property string $x
 * @property string $y
 * @property string $width
 * @property string $height
 * @property FieldBoxType $box_type
 * @property string|null $page_width_pt
 * @property string|null $page_height_pt
 * @property int $page_rotation
 * @property bool $required
 * @property string|null $label
 * @property array<string, mixed>|null $options
 * @property int $sort_order
 */
class TemplateField extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'template_version_id',
        'template_role_id',
        'organization_id',
        'type',
        'page',
        'x',
        'y',
        'width',
        'height',
        'box_type',
        'page_width_pt',
        'page_height_pt',
        'page_rotation',
        'required',
        'label',
        'options',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
            'page' => 'integer',
            'x' => 'decimal:6',
            'y' => 'decimal:6',
            'width' => 'decimal:6',
            'height' => 'decimal:6',
            'box_type' => FieldBoxType::class,
            'page_width_pt' => 'decimal:3',
            'page_height_pt' => 'decimal:3',
            'page_rotation' => 'integer',
            'required' => 'boolean',
            'options' => 'array',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Campos de modelo pertencem a uma versão imutável.');
        });
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    /** @return BelongsTo<TemplateRole, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(TemplateRole::class, 'template_role_id');
    }
}
