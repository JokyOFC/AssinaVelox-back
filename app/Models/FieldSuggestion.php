<?php

namespace App\Models;

use App\Enums\FieldType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Anchors\SuggestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Campo SUGERIDO por âncora ou OCR (Fase 3 §3.2 — docs/fase-3/ancoras-e-ocr.md §5). Nunca é
 * um `signing_field`: só vira campo quando o remetente o confirma no editor. Sugestão
 * `pending` na versão exibível corrente impede o envelope de ficar pronto.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $document_id
 * @property int $document_version_id
 * @property int $anchor_scan_id
 * @property int|null $field_anchor_rule_id
 * @property int|null $recipient_id
 * @property string $source
 * @property string $via
 * @property FieldType $type
 * @property int $page
 * @property string $x
 * @property string $y
 * @property string $width
 * @property string $height
 * @property bool $required
 * @property string|null $label
 * @property string|null $role_hint
 * @property string|null $confidence
 * @property SuggestionStatus $status
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FieldSuggestion extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const SOURCE_MARKER = 'marker';

    public const SOURCE_RULE = 'rule';

    public const SOURCE_LITERAL = 'literal';

    public const VIA_TEXT = 'text';

    public const VIA_OCR = 'ocr';

    /** @var list<string> */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'required' => true,
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
            'required' => 'boolean',
            'confidence' => 'decimal:2',
            'status' => SuggestionStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
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

    /** @return BelongsTo<AnchorScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(AnchorScan::class, 'anchor_scan_id');
    }

    public function isPending(): bool
    {
        return $this->status === SuggestionStatus::Pending;
    }

    public function viaOcr(): bool
    {
        return $this->via === self::VIA_OCR;
    }
}
