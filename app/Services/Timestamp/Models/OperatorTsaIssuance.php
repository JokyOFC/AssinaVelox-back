<?php

namespace App\Services\Timestamp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Livro de emissões da TSA da operadora (tabela `operator_tsa_issuances`).
 *
 * Linha nunca apagada: é a sequência de números de série. Ver a migration 130101.
 *
 * Mora em App\Services\Timestamp\Models porque `app/Models` está fora da área do K-TSA;
 * a integração pode movê-lo sem mudar a tabela.
 *
 * @property int $id
 * @property string $serial
 * @property string $purpose
 * @property string $status
 * @property int|null $organization_id
 * @property string|null $hash_algorithm
 * @property string|null $imprint
 * @property string|null $policy_oid
 * @property Carbon|null $gen_time
 * @property string|null $token_sha256
 * @property string|null $fail_info
 * @property string|null $tsa_cert_fingerprint
 * @property string $environment
 * @property string|null $correlation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OperatorTsaIssuance extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    protected $table = 'operator_tsa_issuances';

    /** @var list<string> */
    protected $fillable = [
        'serial',
        'purpose',
        'status',
        'organization_id',
        'hash_algorithm',
        'imprint',
        'policy_oid',
        'gen_time',
        'token_sha256',
        'fail_info',
        'tsa_cert_fingerprint',
        'environment',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gen_time' => 'datetime',
        ];
    }
}
