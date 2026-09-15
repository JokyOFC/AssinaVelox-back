<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Execução da ação de workflow "Enviar para assinatura" do HubSpot (Fase 3 §3.9, G-CONN,
 * docs/fase-3/conectores.md §5.2). UNIQUE(portal_id, callback_id) = idempotência.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $hubspot_connection_id
 * @property int $portal_id
 * @property string $callback_id
 * @property string|null $object_type
 * @property string|null $object_id
 * @property int|null $envelope_id
 * @property int|null $template_id
 * @property string $status
 * @property string|null $error_code
 * @property array<string, mixed>|null $response
 * @property string $sync_status
 * @property string|null $synced_value
 * @property int $sync_attempts
 * @property string|null $sync_error_code
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HubSpotActionExecution extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_AWAITING_PREPARATION = 'awaiting_preparation';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_FAILED = 'failed';

    public const SYNC_NOT_APPLICABLE = 'not_applicable';

    public const SYNC_PENDING = 'pending';

    public const SYNC_SYNCED = 'synced';

    public const SYNC_FAILED = 'failed';

    protected $table = 'hubspot_action_executions';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'hubspot_connection_id',
        'portal_id',
        'callback_id',
        'object_type',
        'object_id',
        'envelope_id',
        'template_id',
        'status',
        'error_code',
        'response',
        'sync_status',
        'synced_value',
        'sync_attempts',
        'sync_error_code',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portal_id' => 'integer',
            'response' => 'array',
            'sync_attempts' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<HubSpotConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(HubSpotConnection::class, 'hubspot_connection_id');
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
