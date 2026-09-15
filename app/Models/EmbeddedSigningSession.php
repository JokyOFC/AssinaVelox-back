<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sessão de assinatura embutida (Fase 3 §3.9, G-EMBED — docs/fase-3/widget-embutido.md §3).
 *
 * Restrita a UM participante de UM envelope e a UMA origem exata. Nenhum token fica em claro:
 * `token_digest` (URL de uso único, token no fragmento) e `runtime_token_digest` (token de
 * execução, só na memória do widget) são SHA-256; `session_state` — o que no fluxo por e-mail
 * mora na sessão Laravel do navegador — é cifrado com a APP_KEY.
 *
 * Situação derivada (nunca gravada como enum): `pending` (URL ainda não usada e no prazo),
 * `active` (trocada, token de execução vivo), `completed`/`refused` (desfecho registrado por
 * este widget — nunca mascarado por uma revogação posterior), `expired`, `revoked` e `closed`
 * (a pessoa respondeu por outro caminho, como o link do e-mail, ou foi encerrada).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property int $access_link_id
 * @property int|null $api_token_id
 * @property int|null $created_by_user_id
 * @property string $allowed_origin
 * @property string $token_digest
 * @property string|null $runtime_token_digest
 * @property array<string, mixed>|null $session_state
 * @property string|null $idempotency_key_digest
 * @property string|null $request_fingerprint
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $runtime_expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon|null $completed_at
 * @property string|null $outcome
 * @property Carbon|null $last_seen_at
 * @property string|null $used_ip
 * @property string|null $used_user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Envelope $envelope
 * @property-read Recipient $recipient
 */
class EmbeddedSigningSession extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_CLOSED = 'closed';

    public const OUTCOME_COMPLETED = 'completed';

    public const OUTCOME_REFUSED = 'refused';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'recipient_id',
        'access_link_id',
        'api_token_id',
        'created_by_user_id',
        'allowed_origin',
        'token_digest',
        'runtime_token_digest',
        'session_state',
        'idempotency_key_digest',
        'request_fingerprint',
        'expires_at',
        'used_at',
        'runtime_expires_at',
        'revoked_at',
        'revoked_reason',
        'completed_at',
        'outcome',
        'last_seen_at',
        'used_ip',
        'used_user_agent',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_digest',
        'runtime_token_digest',
        'session_state',
        'idempotency_key_digest',
        'request_fingerprint',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'session_state' => 'encrypted:array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'runtime_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
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

    /** @return BelongsTo<RecipientAccessLink, $this> */
    public function accessLink(): BelongsTo
    {
        return $this->belongsTo(RecipientAccessLink::class, 'access_link_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /** A URL de uso único ainda pode ser trocada? */
    public function isRedeemable(): bool
    {
        return ! $this->isRevoked() && ! $this->isUsed() && $this->expires_at->isFuture();
    }

    /** O token de execução (depois da troca) ainda vale? */
    public function hasLiveRuntime(): bool
    {
        return ! $this->isRevoked()
            && $this->runtime_token_digest !== null
            && $this->runtime_expires_at !== null
            && $this->runtime_expires_at->isFuture();
    }

    /**
     * O desfecho registrado por este widget vem antes de tudo (revogar depois do aceite nunca o
     * mascara). `closed`: a pessoa já respondeu por OUTRO caminho (link do e-mail, delegação) ou
     * foi encerrada (cancelamento, prazo) — a sessão não abre mais, mesmo com o token vivo.
     */
    public function status(): string
    {
        return match (true) {
            $this->outcome === self::OUTCOME_COMPLETED => self::STATUS_COMPLETED,
            $this->outcome === self::OUTCOME_REFUSED => self::STATUS_REFUSED,
            $this->isRevoked() => self::STATUS_REVOKED,
            $this->recipientResponded() => self::STATUS_CLOSED,
            $this->isUsed() => $this->hasLiveRuntime() ? self::STATUS_ACTIVE : self::STATUS_EXPIRED,
            $this->expires_at->isPast() => self::STATUS_EXPIRED,
            default => self::STATUS_PENDING,
        };
    }

    /** O participante já está num estado terminal (assinou, recusou, delegou, cancelado, expirado)? */
    public function recipientResponded(): bool
    {
        $status = Recipient::withoutOrganizationScope()->whereKey($this->recipient_id)->value('status');
        $status = $status instanceof RecipientStatus ? $status : RecipientStatus::tryFrom((string) $status);

        return $status?->isTerminal() ?? false;
    }
}
