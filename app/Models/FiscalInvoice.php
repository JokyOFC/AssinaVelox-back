<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * NFS-e de um pagamento (Fase 2, onda D — roadmap §2.21, classe B). Uma por pagamento.
 *
 * Enquanto a emissão real estiver bloqueada, só o simulador identificado cria linhas, sempre com
 * `status = simulated` e sem número, código de verificação, PDF ou XML — nada que se confunda
 * com documento fiscal.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $payment_id
 * @property string $provider
 * @property string $status
 * @property string|null $external_id
 * @property string|null $number
 * @property string|null $series
 * @property string|null $verification_code
 * @property string $idempotency_key
 * @property string|null $pdf_path
 * @property string|null $xml_path
 * @property Carbon|null $issued_at
 * @property Carbon|null $canceled_at
 * @property string|null $error
 * @property string|null $correlation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Payment $payment
 */
class FiscalInvoice extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SIMULATED = 'simulated';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'payment_id',
        'provider',
        'status',
        'external_id',
        'number',
        'series',
        'verification_code',
        'idempotency_key',
        'pdf_path',
        'xml_path',
        'issued_at',
        'canceled_at',
        'error',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
