<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma assinatura PAdES aplicada com o certificado A1 do participante, POR DOCUMENTO
 * (Fase 2 §2.12). Imutável depois de criada. `base_document_version_id` é único no banco:
 * duas assinaturas nunca partem da mesma revisão (sem revisões irmãs).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $participant_signature_request_id
 * @property int $recipient_id
 * @property int $document_id
 * @property int|null $signature_acceptance_id
 * @property int|null $certificate_reference_id
 * @property int $base_document_version_id
 * @property int $signed_document_version_id
 * @property int $revision_index
 * @property string $field_name
 * @property string $profile
 * @property string|null $subject
 * @property string|null $issuer
 * @property string|null $serial_number
 * @property string|null $fingerprint_sha256
 * @property Carbon|null $not_before
 * @property Carbon|null $not_after
 * @property array<string, mixed>|null $validation_result
 * @property Carbon $signed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ParticipantSignature extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'participant_signature_request_id',
        'recipient_id',
        'document_id',
        'signature_acceptance_id',
        'certificate_reference_id',
        'base_document_version_id',
        'signed_document_version_id',
        'revision_index',
        'field_name',
        'profile',
        'subject',
        'issuer',
        'serial_number',
        'fingerprint_sha256',
        'not_before',
        'not_after',
        'validation_result',
        'signed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision_index' => 'integer',
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'validation_result' => 'array',
            'signed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ParticipantSignatureRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ParticipantSignatureRequest::class, 'participant_signature_request_id');
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
    public function signedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'signed_document_version_id');
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'base_document_version_id');
    }
}
