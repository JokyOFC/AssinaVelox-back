<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Indicação: organização atribuída a um afiliado (Fase 3 §3.10). `organization_id` é UNIQUE.
 *
 * Estados: `active` (gera comissão), `held` (em revisão humana — as comissões nascem mas não
 * são aprovadas até a revisão) e `rejected` (autoindicação ou recusa do revisor — não gera
 * comissão). Toda decisão automática guarda a regra em `block_reasons` e pode ser revista por
 * uma pessoa (LGPD art. 20).
 *
 * @property int $id
 * @property string $ulid
 * @property int $affiliate_id
 * @property int|null $organization_id
 * @property int|null $user_id
 * @property string $source
 * @property string $status
 * @property list<string>|null $block_reasons
 * @property string|null $signup_ip_hash
 * @property Carbon|null $clicked_at
 * @property Carbon $attributed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $review_requested_at
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Affiliate $affiliate
 * @property-read Organization|null $organization
 */
class Referral extends Model
{
    use HasPublicUlid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_HELD = 'held';

    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_LINK = 'link';

    /** Regras que BARRAM a comissão (autoindicação). */
    public const REASON_SAME_USER = 'same_user';

    public const REASON_SAME_EMAIL = 'same_email';

    public const REASON_SAME_DOMAIN = 'same_domain';

    public const REASON_SAME_IP = 'same_ip';

    /** Regra que SEGURA a comissão até revisão (possível conta duplicada). */
    public const REASON_DUPLICATE_IP = 'duplicate_ip';

    /** Regra que SEGURA a comissão: o afiliado é dono ou administrador da organização indicada. */
    public const REASON_SAME_MEMBER = 'same_member';

    public const SELF_REFERRAL_REASONS = [self::REASON_SAME_USER, self::REASON_SAME_EMAIL, self::REASON_SAME_DOMAIN, self::REASON_SAME_IP];

    /** @var list<string> */
    protected $fillable = [
        'affiliate_id',
        'organization_id',
        'user_id',
        'source',
        'status',
        'block_reasons',
        'signup_ip_hash',
        'clicked_at',
        'attributed_at',
        'expires_at',
        'review_requested_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
    ];

    /** @var list<string> */
    protected $hidden = ['signup_ip_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'block_reasons' => 'array',
            'clicked_at' => 'datetime',
            'attributed_at' => 'datetime',
            'expires_at' => 'datetime',
            'review_requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_ACTIVE => 'Ativa',
            self::STATUS_HELD => 'Em revisão',
            self::STATUS_REJECTED => 'Não elegível',
            default => $status,
        };
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_SAME_USER => 'Mesmo usuário do afiliado',
            self::REASON_SAME_EMAIL => 'Mesmo e-mail do afiliado',
            self::REASON_SAME_DOMAIN => 'Mesmo domínio corporativo do afiliado',
            self::REASON_SAME_IP => 'Mesmo IP usado pelo afiliado dentro da janela',
            self::REASON_DUPLICATE_IP => 'Mesmo IP de outra indicação do afiliado dentro da janela',
            self::REASON_SAME_MEMBER => 'O afiliado é dono ou administrador da organização indicada',
            default => $reason,
        };
    }
}
