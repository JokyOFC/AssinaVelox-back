<?php

namespace App\Services\InPerson\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A vez de um participante numa sessão presencial (`in_person_turns`,
 * docs/fase-2/presencial-e-lote.md §2.3). Uma vez encerrada, não reabre.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $in_person_session_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property int|null $signing_session_id
 * @property int|null $signature_acceptance_id
 * @property string|null $turn_secret_digest
 * @property string $status
 * @property string|null $close_reason
 * @property Carbon $started_at
 * @property Carbon|null $authenticated_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read InPersonSession|null $session
 * @property-read Recipient|null $recipient
 */
class InPersonTurn extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_CLOSED = 'closed';

    /** Motivos de `close_reason`. */
    public const CLOSE_ACCEPTED = 'accepted';

    public const CLOSE_LOCKED = 'locked';

    public const CLOSE_SESSION_ENDED = 'session_ended';

    public const CLOSE_NOT_SIGNABLE = 'not_signable';

    protected $table = 'in_person_turns';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'in_person_session_id',
        'envelope_id',
        'recipient_id',
        'signing_session_id',
        'signature_acceptance_id',
        'turn_secret_digest',
        'status',
        'close_reason',
        'started_at',
        'authenticated_at',
        'finished_at',
    ];

    /** @var list<string> */
    protected $hidden = ['turn_secret_digest'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'authenticated_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InPersonSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(InPersonSession::class, 'in_person_session_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<SigningSession, $this> */
    public function signingSession(): BelongsTo
    {
        return $this->belongsTo(SigningSession::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<SignatureAcceptance, $this> */
    public function acceptance(): BelongsTo
    {
        return $this->belongsTo(SignatureAcceptance::class, 'signature_acceptance_id')->withoutGlobalScopes();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
