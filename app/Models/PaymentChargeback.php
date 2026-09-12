<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Contestação (chargeback) de um pagamento nosso, consultada em GET /v1/chargebacks/{id}
 * (Fase 2, onda D). Registrar a contestação NUNCA desfaz documentos: envelopes concluídos,
 * evidências e verificação pública continuam como estão.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $payment_id
 * @property string $provider
 * @property string $provider_chargeback_id
 * @property int $amount_cents
 * @property string $currency
 * @property string|null $reason
 * @property bool|null $coverage_applied
 * @property string|null $documentation_status
 * @property Carbon|null $documentation_deadline_at
 * @property bool $live_mode
 * @property Carbon $received_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Payment $payment
 */
class PaymentChargeback extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'payment_id',
        'provider',
        'provider_chargeback_id',
        'amount_cents',
        'currency',
        'reason',
        'coverage_applied',
        'documentation_status',
        'documentation_deadline_at',
        'live_mode',
        'received_at',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'coverage_applied' => 'boolean',
            'live_mode' => 'boolean',
            'documentation_deadline_at' => 'datetime',
            'received_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Rótulo honesto do andamento: `coverage_applied = true` é decisão a favor do vendedor
     * (documentação oficial); sem decisão, a disputa segue aberta (pode levar meses).
     */
    public function outcomeLabel(): string
    {
        return match ($this->coverage_applied) {
            true => 'Decidida a favor da operadora',
            false => 'Em disputa',
            null => 'Em disputa',
        };
    }
}
