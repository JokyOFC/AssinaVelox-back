<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lançamento do razão de comissões (Fase 3 §3.10). Centavos com sinal + moeda.
 *
 * - `commission`: calculada sobre um pagamento APROVADO; nasce `pending` e passa a `approved`
 *   depois do prazo de estorno (`available_at`); vira `reversed` se o pagamento for estornado
 *   ou contestado ANTES da aprovação.
 * - `reversal`: lançamento NEGATIVO criado quando o estorno/contestação chega DEPOIS da
 *   aprovação; nasce `approved` e entra no próximo lote.
 * - `adjustment`: diferença (normalmente negativa) de um estorno parcial após a aprovação.
 * - `paid`: incluído num lote marcado como pago pela operadora.
 *
 * @property int $id
 * @property string $ulid
 * @property int $affiliate_id
 * @property int|null $referral_id
 * @property int|null $organization_id
 * @property int|null $payment_id
 * @property string $kind
 * @property string $idempotency_key
 * @property int $base_amount_cents
 * @property int $rate_bp
 * @property int $amount_cents
 * @property string $currency
 * @property string $environment
 * @property string $status
 * @property Carbon|null $available_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 * @property int|null $payout_batch_id
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Affiliate $affiliate
 * @property-read Referral|null $referral
 * @property-read Payment|null $payment
 * @property-read PayoutBatch|null $payoutBatch
 */
class Commission extends Model
{
    use HasPublicUlid;

    public const KIND_COMMISSION = 'commission';

    public const KIND_REVERSAL = 'reversal';

    public const KIND_ADJUSTMENT = 'adjustment';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PAID, self::STATUS_REVERSED];

    public const REASON_REFUND = 'refund';

    public const REASON_CHARGEBACK = 'chargeback';

    public const REASON_PARTIAL_REFUND = 'partial_refund';

    public const REASON_REFERRAL_REJECTED = 'referral_rejected';

    /** @var list<string> */
    protected $fillable = [
        'affiliate_id',
        'referral_id',
        'organization_id',
        'payment_id',
        'kind',
        'idempotency_key',
        'base_amount_cents',
        'rate_bp',
        'amount_cents',
        'currency',
        'environment',
        'status',
        'available_at',
        'approved_at',
        'reversed_at',
        'reversal_reason',
        'payout_batch_id',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_amount_cents' => 'integer',
            'rate_bp' => 'integer',
            'amount_cents' => 'integer',
            'available_at' => 'datetime',
            'approved_at' => 'datetime',
            'reversed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** @return BelongsTo<Referral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<PayoutBatch, $this> */
    public function payoutBatch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class);
    }

    public static function commissionKey(int $paymentId): string
    {
        return 'payment:'.$paymentId.':commission';
    }

    public static function reversalKey(int $paymentId): string
    {
        return 'payment:'.$paymentId.':reversal';
    }

    public static function adjustmentKey(int $paymentId, int $netCents): string
    {
        return 'payment:'.$paymentId.':adjust:'.$netCents;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_APPROVED => 'Aprovada',
            self::STATUS_PAID => 'Paga',
            self::STATUS_REVERSED => 'Revertida',
            default => $status,
        };
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_COMMISSION => 'Comissão',
            self::KIND_REVERSAL => 'Estorno de comissão',
            self::KIND_ADJUSTMENT => 'Ajuste',
            default => $kind,
        };
    }

    /**
     * Motivo mostrado AO AFILIADO (portal e CSV): estorno e contestação do cliente indicado
     * saem com um texto neutro — a contestação de cartão é dado financeiro do cliente (LGPD;
     * afiliados.md §7). O rótulo detalhado ({@see self::reasonLabel()}) fica no painel interno.
     */
    public static function affiliateReasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            self::REASON_REFUND, self::REASON_CHARGEBACK => 'Pagamento revertido',
            default => self::reasonLabel($reason),
        };
    }

    public static function reasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            self::REASON_REFUND => 'Pagamento estornado',
            self::REASON_CHARGEBACK => 'Pagamento contestado',
            self::REASON_PARTIAL_REFUND => 'Estorno parcial',
            self::REASON_REFERRAL_REJECTED => 'Indicação não elegível após revisão',
            default => $reason,
        };
    }
}
