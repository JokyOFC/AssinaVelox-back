<?php

namespace App\Services\Signing\GovBr\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma devolução de arquivo assinado fora da plataforma — aceita ou recusada (P3-GOV).
 * Somente inclusão: não há `updated_at`, e nada aqui é alterado depois de gravado.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $external_signature_request_id
 * @property int $recipient_id
 * @property string|null $received_sha256
 * @property int $received_size
 * @property string|null $expected_revision_sha256
 * @property string $outcome
 * @property string|null $rejection_code
 * @property array<string, mixed>|null $checks
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 */
class ExternalSignatureReturn extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const UPDATED_AT = null;

    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_REJECTED = 'rejected';

    protected $table = 'external_signature_returns';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'external_signature_request_id',
        'recipient_id',
        'received_sha256',
        'received_size',
        'expected_revision_sha256',
        'outcome',
        'rejection_code',
        'checks',
        'ip_address',
    ];

    /** @var list<string> */
    protected $hidden = ['ip_address'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_size' => 'integer',
            'checks' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExternalSignatureRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ExternalSignatureRequest::class, 'external_signature_request_id');
    }
}
