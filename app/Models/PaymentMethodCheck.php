<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registro de uma consulta a GET /v1/payment_methods com a credencial da conta vendedora
 * (Fase 2, onda D). É a base para oferecer (ou não) Pix, boleto e cartão no Checkout Pro: nada de
 * supor que a conta tem um meio ativo.
 *
 * @property int $id
 * @property string $provider
 * @property string $environment
 * @property string $status
 * @property list<array{id: string, name: string|null, payment_type_id: string|null, status: string|null}>|null $methods
 * @property string|null $error
 * @property int|null $checked_by_user_id
 * @property string|null $correlation_id
 * @property Carbon $checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentMethodCheck extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'environment',
        'status',
        'methods',
        'error',
        'checked_by_user_id',
        'correlation_id',
        'checked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'methods' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }
}
