<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Anchors\AnchorScanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma busca de âncoras em UM documento, ou uma passagem de OCR nas páginas sem texto
 * (`trigger = ocr`, `parent_id` = a busca de texto). Fase 3 §3.2 — docs/fase-3/ancoras-e-ocr.md §3.
 *
 * `query` guarda o que foi PROCURADO — nunca o texto do documento:
 * `{markers: bool, literals: [{id, text, field_type, recipient, placement, offset_x_pt,
 * offset_y_pt, width_pt, height_pt, required, occurrence, rule}], ocr_pages?: int[]}`.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $document_id
 * @property int|null $document_version_id
 * @property int|null $parent_id
 * @property int|null $template_id
 * @property int|null $requested_by_user_id
 * @property string $trigger
 * @property AnchorScanStatus $status
 * @property array<string, mixed> $query
 * @property string|null $ocr_engine
 * @property int $pages_scanned
 * @property list<int>|null $pages_without_text
 * @property int $matches_count
 * @property int $suggestions_count
 * @property bool $truncated
 * @property int $attempts
 * @property string|null $failure_code
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AnchorScan extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_TEMPLATE = 'template';

    public const TRIGGER_OCR = 'ocr';

    /** @var list<string> */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'pages_scanned' => 0,
        'matches_count' => 0,
        'suggestions_count' => 0,
        'truncated' => false,
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AnchorScanStatus::class,
            'query' => 'array',
            'pages_without_text' => 'array',
            'pages_scanned' => 'integer',
            'matches_count' => 'integer',
            'suggestions_count' => 'integer',
            'truncated' => 'boolean',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<FieldSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(FieldSuggestion::class);
    }

    public function isOcr(): bool
    {
        return $this->trigger === self::TRIGGER_OCR;
    }
}
