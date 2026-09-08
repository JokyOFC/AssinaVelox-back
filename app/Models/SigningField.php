<?php

namespace App\Models;

use App\Enums\FieldBoxType;
use App\Enums\FieldType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\SigningFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Geometria normalizada [0,1] em relação ao box exibido (CropBox por padrão), origem no
 * canto superior esquerdo já considerando a rotação da página.
 *
 * @property int $id
 * @property string $ulid
 * @property int $envelope_id
 * @property int $document_version_id
 * @property int $recipient_id
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SigningField extends Model
{
    /** @use HasFactory<SigningFieldFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'envelope_id',
        'document_version_id',
        'recipient_id',
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

    /** @var array<string, mixed> */
    protected $attributes = [
        'box_type' => FieldBoxType::CropBox->value,
        'page_rotation' => 0,
        'required' => true,
        'sort_order' => 0,
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

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /** @return HasOne<SigningFieldValue, $this> */
    public function value(): HasOne
    {
        return $this->hasOne(SigningFieldValue::class);
    }

    /**
     * Geometria dentro dos limites [0,1] (validação de domínio, também feita no request).
     */
    public function hasValidGeometry(): bool
    {
        $x = (float) $this->x;
        $y = (float) $this->y;
        $w = (float) $this->width;
        $h = (float) $this->height;

        return $x >= 0 && $y >= 0 && $w > 0 && $h > 0 && ($x + $w) <= 1 && ($y + $h) <= 1;
    }
}
