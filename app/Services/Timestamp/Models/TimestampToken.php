<?php

namespace App\Services\Timestamp\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Envelope;
use App\Services\Timestamp\TsaKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Carimbo do tempo guardado (tabela `timestamp_tokens`, roadmap §2.13).
 *
 * O token DER fica no disco privado (`storage_path`); aqui só os metadados públicos.
 * `tsa_kind = icp_brasil` só é aceito por App\Services\Timestamp\TimestampTokens quando
 * vier de um provedor de ACT credenciada configurado — hoje nenhum (regra T3).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $envelope_id
 * @property int|null $dossier_export_id
 * @property int|null $document_version_id
 * @property int|null $operator_tsa_issuance_id
 * @property string $purpose
 * @property TsaKind $tsa_kind
 * @property string $provider
 * @property string $environment
 * @property bool $test_certificate
 * @property string $status
 * @property string $hash_algorithm
 * @property string $imprint
 * @property string $serial
 * @property Carbon $gen_time
 * @property string|null $policy_oid
 * @property string|null $tsa_subject
 * @property string|null $tsa_cert_fingerprint
 * @property int|null $accuracy_ms
 * @property string $token_sha256
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property array<string, mixed>|null $verification
 * @property Carbon|null $created_at
 */
class TimestampToken extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const UPDATED_AT = null;

    public const PURPOSE_DOSSIER_MANIFEST = 'dossier_manifest';

    public const PURPOSE_SIGNATURE = 'signature';

    public const PURPOSE_OTHER = 'other';

    protected $table = 'timestamp_tokens';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'dossier_export_id',
        'document_version_id',
        'operator_tsa_issuance_id',
        'purpose',
        'tsa_kind',
        'provider',
        'environment',
        'test_certificate',
        'status',
        'hash_algorithm',
        'imprint',
        'serial',
        'gen_time',
        'policy_oid',
        'tsa_subject',
        'tsa_cert_fingerprint',
        'accuracy_ms',
        'token_sha256',
        'storage_disk',
        'storage_path',
        'verification',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tsa_kind' => TsaKind::class,
            'gen_time' => 'datetime',
            'test_certificate' => 'boolean',
            'accuracy_ms' => 'integer',
            'verification' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /**
     * Teste quando o ambiente declarado não é produção OU quando o certificado da TSA que o
     * emitiu é de teste — a mesma regra do dossiê (`carimbo.json` → `test_tsa`). Declarar
     * `environment=production` não transforma a TSA de teste em produção.
     */
    public function isTest(): bool
    {
        return $this->environment !== 'production' || (bool) $this->test_certificate;
    }
}
