<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Trilha da retenção e da preservação (Fase 2 §2.19) — APPEND-ONLY.
 *
 * Tipos (`event_type`): `retention_policy.updated`, `legal_hold.placed`, `legal_hold.released`,
 * `legal_hold.blocked_deletion`, `retention.envelope_purged`, `retention.captures_purged`,
 * `retention.dossiers_purged`, `retention.audit_trail_purged`, `retention.skipped_by_hold`.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $envelope_id
 * @property int|null $legal_hold_id
 * @property string|null $subject_ulid
 * @property string $event_type
 * @property int|null $actor_user_id
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
class RetentionEvent extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const UPDATED_AT = null;

    public const POLICY_UPDATED = 'retention_policy.updated';

    public const HOLD_PLACED = 'legal_hold.placed';

    public const HOLD_RELEASED = 'legal_hold.released';

    public const HOLD_BLOCKED_DELETION = 'legal_hold.blocked_deletion';

    public const ENVELOPE_PURGED = 'retention.envelope_purged';

    public const CAPTURES_PURGED = 'retention.captures_purged';

    public const DOSSIERS_PURGED = 'retention.dossiers_purged';

    public const AUDIT_TRAIL_PURGED = 'retention.audit_trail_purged';

    public const SKIPPED_BY_HOLD = 'retention.skipped_by_hold';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'legal_hold_id',
        'subject_ulid',
        'event_type',
        'actor_user_id',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('retention_events é append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('retention_events é append-only.');
        });
    }
}
