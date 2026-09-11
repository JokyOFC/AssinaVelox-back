<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Services\AdminLog\PlatformAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Trilha das ações da equipe da plataforma — APPEND-ONLY (roadmap §2.14, T7).
 * Sem updated_at; o model recusa update/delete.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $actor_user_id
 * @property PlatformAction $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property int|null $organization_id
 * @property array<string, mixed>|null $payload
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $correlation_id
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
class PlatformAuditEvent extends Model
{
    use HasPublicUlid;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'actor_user_id',
        'action',
        'target_type',
        'target_id',
        'organization_id',
        'payload',
        'ip_address',
        'user_agent',
        'correlation_id',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => PlatformAction::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformAuditEvent $event): void {
            $event->occurred_at ??= Carbon::now();
        });

        static::updating(function (): never {
            throw new LogicException('platform_audit_events é append-only: registros não podem ser alterados.');
        });

        static::deleting(function (): never {
            throw new LogicException('platform_audit_events é append-only: registros não podem ser removidos.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
