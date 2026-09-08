<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Trilha de auditoria — APPEND-ONLY. Sem updated_at; o model recusa update/delete e o
 * usuário MySQL da aplicação em produção não deve ter UPDATE/DELETE nesta tabela.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $envelope_id
 * @property int|null $recipient_id
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property AuditEventType $event_type
 * @property array<string, mixed>|null $payload
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $correlation_id
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use BelongsToOrganization, HasFactory, HasPublicUlid;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'recipient_id',
        'actor_type',
        'actor_id',
        'event_type',
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
            'actor_type' => ActorType::class,
            'event_type' => AuditEventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditEvent $event): void {
            $event->occurred_at ??= Carbon::now();
        });

        static::updating(function (): never {
            throw new LogicException('audit_events é append-only: registros não podem ser alterados.');
        });

        static::deleting(function (): never {
            throw new LogicException('audit_events é append-only: registros não podem ser removidos.');
        });
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /**
     * Usuário autor, quando actor_type = user.
     *
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Signatário autor, quando actor_type = recipient.
     *
     * @return BelongsTo<Recipient, $this>
     */
    public function actorRecipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class, 'actor_id');
    }

    /**
     * @return 'ok'|'info'|'warn'
     */
    public function kind(): string
    {
        return $this->event_type->kind();
    }
}
