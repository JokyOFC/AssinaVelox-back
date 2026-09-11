<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PIN do remetente para um participante (Fase 2 §2.9, docs/fase-2/canais-e-pin.md).
 *
 * Só o hash é guardado (App\Services\Signing\Channels\SenderPins::hash()). O atributo
 * `pin_hash` fica oculto na serialização para nunca aparecer em resposta ou log.
 *
 * @property int $id
 * @property int $recipient_id
 * @property int $envelope_id
 * @property int $organization_id
 * @property string $pin_hash
 * @property int $failed_attempts
 * @property int $lockouts
 * @property Carbon|null $locked_until
 * @property Carbon|null $blocked_at
 * @property Carbon|null $last_failed_at
 * @property Carbon|null $verified_at
 * @property int|null $set_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecipientPin extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = [
        'recipient_id',
        'envelope_id',
        'organization_id',
        'pin_hash',
        'failed_attempts',
        'lockouts',
        'locked_until',
        'blocked_at',
        'last_failed_at',
        'verified_at',
        'set_by_user_id',
    ];

    /** @var list<string> */
    protected $hidden = ['pin_hash'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'failed_attempts' => 0,
        'lockouts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'lockouts' => 'integer',
            'locked_until' => 'datetime',
            'blocked_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
