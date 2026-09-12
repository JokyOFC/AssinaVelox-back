<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Divergência encontrada numa conciliação (Fase 2, onda D). Marcar como revisada não muda o
 * pagamento: quem decide o estado continua sendo o webhook + GET /v1/payments/{id}.
 *
 * @property int $id
 * @property int $reconciliation_run_id
 * @property int|null $payment_id
 * @property int|null $organization_id
 * @property string|null $provider_payment_id
 * @property string|null $external_reference
 * @property string $divergence
 * @property string|null $local_status
 * @property string|null $provider_status
 * @property int|null $local_amount_cents
 * @property int|null $provider_amount_cents
 * @property string|null $local_currency
 * @property string|null $provider_currency
 * @property string|null $note
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_user_id
 * @property string|null $resolution_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ReconciliationRun $run
 * @property-read Payment|null $payment
 * @property-read Organization|null $organization
 */
class ReconciliationItem extends Model
{
    public const STATUS_MISMATCH = 'status_mismatch';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const CURRENCY_MISMATCH = 'currency_mismatch';

    public const MISSING_LOCAL = 'missing_local';

    /** @var list<string> */
    protected $fillable = [
        'reconciliation_run_id',
        'payment_id',
        'organization_id',
        'provider_payment_id',
        'external_reference',
        'divergence',
        'local_status',
        'provider_status',
        'local_amount_cents',
        'provider_amount_cents',
        'local_currency',
        'provider_currency',
        'note',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_amount_cents' => 'integer',
            'provider_amount_cents' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReconciliationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function divergenceLabel(): string
    {
        return match ($this->divergence) {
            self::STATUS_MISMATCH => 'Status diferente',
            self::AMOUNT_MISMATCH => 'Valor diferente',
            self::CURRENCY_MISMATCH => 'Moeda diferente',
            self::MISSING_LOCAL => 'Sem pagamento correspondente aqui',
            default => $this->divergence,
        };
    }
}
