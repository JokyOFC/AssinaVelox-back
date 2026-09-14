<?php

namespace App\Services\Signing\GovBr\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Signing\GovBr\ExternalSignatureRequestStatus;
use App\Services\Signing\GovBr\GovBrSignatureKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pedido de assinatura fora da plataforma com devolução do PDF (Fase 3 §3.5, P3-GOV).
 *
 * O modelo mora na área do P3-GOV (e não em `app/Models`) por decisão de escopo desta parte;
 * a integração pode movê-lo sem mudar a tabela.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property int $document_id
 * @property string $provider
 * @property ExternalSignatureRequestStatus $status
 * @property int|null $expected_document_version_id
 * @property string|null $expected_revision_sha256
 * @property int|null $expected_revision_size
 * @property Carbon|null $reserved_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $window_expires_at
 * @property int $attempts
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int|null $signed_document_version_id
 * @property GovBrSignatureKind|null $signature_kind
 * @property bool $trusted
 * @property string|null $field_name
 * @property string|null $signer_subject
 * @property string|null $signer_issuer
 * @property string|null $signer_serial
 * @property string|null $signer_fingerprint_sha256
 * @property Carbon|null $signer_not_before
 * @property Carbon|null $signer_not_after
 * @property string|null $holder_name
 * @property string|null $holder_cpf_masked
 * @property string|null $holder_cpf_match
 * @property bool $is_test_certificate
 * @property array<string, mixed>|null $validation_result
 * @property Carbon|null $completed_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExternalSignatureRequest extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const PROVIDER_GOVBR_PORTAL = 'govbr_portal';

    protected $table = 'external_signature_requests';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'recipient_id',
        'document_id',
        'provider',
        'status',
        'expected_document_version_id',
        'expected_revision_sha256',
        'expected_revision_size',
        'reserved_at',
        'expires_at',
        'window_expires_at',
        'attempts',
        'failure_code',
        'failure_message',
        'signed_document_version_id',
        'signature_kind',
        'trusted',
        'field_name',
        'signer_subject',
        'signer_issuer',
        'signer_serial',
        'signer_fingerprint_sha256',
        'signer_not_before',
        'signer_not_after',
        'holder_name',
        'holder_cpf_masked',
        'holder_cpf_match',
        'is_test_certificate',
        'validation_result',
        'completed_at',
        'closed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'provider' => self::PROVIDER_GOVBR_PORTAL,
        'status' => 'requested',
        'attempts' => 0,
        'trusted' => false,
        'is_test_certificate' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExternalSignatureRequestStatus::class,
            'signature_kind' => GovBrSignatureKind::class,
            'expected_revision_size' => 'integer',
            'reserved_at' => 'datetime',
            'expires_at' => 'datetime',
            'window_expires_at' => 'datetime',
            'attempts' => 'integer',
            'trusted' => 'boolean',
            'signer_not_before' => 'datetime',
            'signer_not_after' => 'datetime',
            'is_test_certificate' => 'boolean',
            'validation_result' => 'array',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
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

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function expectedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'expected_document_version_id');
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function signedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'signed_document_version_id');
    }

    public function reservationActive(?Carbon $now = null): bool
    {
        return $this->status === ExternalSignatureRequestStatus::Pending
            && $this->expires_at !== null
            && $this->expires_at->gt($now ?? Carbon::now());
    }
}
