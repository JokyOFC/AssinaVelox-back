<?php

namespace App\Services\InPerson\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Envelope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sessão presencial em tablet (`in_person_sessions`, docs/fase-2/presencial-e-lote.md §2).
 *
 * Mora em `App\Services\InPerson\Models` porque `app/Models` está fora da área C-PRES (mesma
 * decisão do C-ID); a integração pode movê-lo sem mudar a tabela.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int|null $host_user_id
 * @property string $device_label
 * @property string|null $device_secret_digest
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon $last_activity_at
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property int|null $ended_by_user_id
 * @property int|null $current_turn_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Envelope|null $envelope
 * @property-read User|null $host
 */
class InPersonSession extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_EXPIRED = 'expired';

    /** Motivos de encerramento gravados em `end_reason`. */
    public const END_HOST = 'host';

    public const END_DEVICE = 'device';

    public const END_IDLE = 'idle';

    public const END_MAX_DURATION = 'max_duration';

    public const END_ENVELOPE_CLOSED = 'envelope_closed';

    public const END_REPLACED = 'replaced';

    protected $table = 'in_person_sessions';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'host_user_id',
        'device_label',
        'device_secret_digest',
        'status',
        'started_at',
        'last_activity_at',
        'expires_at',
        'ended_at',
        'end_reason',
        'ended_by_user_id',
        'current_turn_id',
        'ip_address',
        'user_agent',
    ];

    /** @var list<string> */
    protected $hidden = ['device_secret_digest'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /** @return HasMany<InPersonTurn, $this> */
    public function turns(): HasMany
    {
        return $this->hasMany(InPersonTurn::class, 'in_person_session_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
