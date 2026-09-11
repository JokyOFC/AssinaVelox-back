<?php

namespace App\Services\Batch\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Item do lote (`batch_signing_items`): uma participação pendente congelada na emissão.
 *
 * @property int $id
 * @property string $ulid
 * @property int $batch_signing_session_id
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property int|null $signing_session_id
 * @property int|null $signature_acceptance_id
 * @property int $position
 * @property string $status
 * @property Carbon|null $opened_at
 * @property Carbon|null $authorized_at
 * @property string|null $last_error_code
 * @property Carbon|null $last_error_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Envelope|null $envelope
 * @property-read Recipient|null $recipient
 */
class BatchSigningItem extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_OPEN = 'open';

    public const STATUS_AUTHORIZED = 'authorized';

    protected $table = 'batch_signing_items';

    /** @var list<string> */
    protected $fillable = [
        'batch_signing_session_id',
        'organization_id',
        'envelope_id',
        'recipient_id',
        'signing_session_id',
        'signature_acceptance_id',
        'position',
        'status',
        'opened_at',
        'authorized_at',
        'last_error_code',
        'last_error_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'opened_at' => 'datetime',
            'authorized_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BatchSigningSession, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BatchSigningSession::class, 'batch_signing_session_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<SignatureAcceptance, $this> */
    public function acceptance(): BelongsTo
    {
        return $this->belongsTo(SignatureAcceptance::class, 'signature_acceptance_id')->withoutGlobalScopes();
    }
}
