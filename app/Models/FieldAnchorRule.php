<?php

namespace App\Models;

use App\Enums\FieldType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Anchors\AnchorPlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Regra de âncora de um modelo (Fase 3 §3.2, F-ANCHOR — docs/fase-3/ancoras-e-ocr.md §4).
 *
 * "Onde o documento gerado tiver `pattern` (texto literal), sugira um campo `field_type` para
 * o participante na posição `role_position` da lista do modelo." Só SUGERE: a sugestão passa
 * pela revisão do remetente no editor. `pattern` nunca é expressão regular.
 *
 * As regras pertencem ao MODELO (não à versão): valem para a versão corrente no momento do
 * uso, e o participante é localizado pela posição 1..N da lista de papéis dessa versão.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $template_id
 * @property string $pattern
 * @property FieldType $field_type
 * @property int|null $role_position
 * @property string|null $role_name
 * @property AnchorPlacement $placement
 * @property string $offset_x_pt
 * @property string $offset_y_pt
 * @property string|null $width_pt
 * @property string|null $height_pt
 * @property bool $required
 * @property string $occurrence
 * @property int $sort_order
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FieldAnchorRule extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const OCCURRENCE_ALL = 'all';

    public const OCCURRENCE_FIRST = 'first';

    /** Tipos que uma regra pode sugerir (os da Fase 1; `cpf`/`stamp` dependem de outras flags). */
    public const FIELD_TYPES = ['signature', 'initials', 'name', 'date', 'text', 'checkbox'];

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'template_id',
        'pattern',
        'field_type',
        'role_position',
        'role_name',
        'placement',
        'offset_x_pt',
        'offset_y_pt',
        'width_pt',
        'height_pt',
        'required',
        'occurrence',
        'sort_order',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'placement' => 'below',
        'offset_x_pt' => 0,
        'offset_y_pt' => 0,
        'required' => true,
        'occurrence' => self::OCCURRENCE_ALL,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_type' => FieldType::class,
            'role_position' => 'integer',
            'placement' => AnchorPlacement::class,
            'offset_x_pt' => 'decimal:2',
            'offset_y_pt' => 'decimal:2',
            'width_pt' => 'decimal:2',
            'height_pt' => 'decimal:2',
            'required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** Identificador do literal no spec do pdftool (`[A-Za-z0-9_-]{1,40}`). */
    public function literalId(): string
    {
        return 'r'.$this->getKey();
    }
}
