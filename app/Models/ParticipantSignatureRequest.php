<?php

namespace App\Models;

use App\Enums\ParticipantSignatureRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pedido de assinatura com o certificado A1 do PRÓPRIO participante (Fase 2 §2.12, K-A1).
 *
 * Guarda a escolha, o consentimento específico e os fatos PÚBLICOS do certificado. Nunca o
 * PFX nem a senha: enquanto espera o worker, o conjunto fica cifrado num arquivo temporário
 * identificado por `sealed_ulid` — a coluna guarda só o identificador, oculta na serialização.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property ParticipantSignatureRequestStatus $status
 * @property string|null $consent_version
 * @property string|null $consent_statement
 * @property Carbon|null $consented_at
 * @property string|null $consent_ip
 * @property string|null $subject
 * @property string|null $subject_cn
 * @property string|null $issuer
 * @property string|null $issuer_cn
 * @property string|null $serial_number
 * @property string|null $fingerprint_sha256
 * @property Carbon|null $not_before
 * @property Carbon|null $not_after
 * @property string|null $holder_cpf_masked
 * @property bool $is_test_certificate
 * @property array<string, mixed>|null $certificate_facts
 * @property string|null $sealed_ulid
 * @property Carbon|null $sealed_expires_at
 * @property Carbon|null $window_expires_at
 * @property int $attempts
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property Carbon|null $queued_at
 * @property Carbon|null $applied_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ParticipantSignatureRequest extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'envelope_id',
        'recipient_id',
        'status',
        'consent_version',
        'consent_statement',
        'consented_at',
        'consent_ip',
        'subject',
        'subject_cn',
        'issuer',
        'issuer_cn',
        'serial_number',
        'fingerprint_sha256',
        'not_before',
        'not_after',
        'holder_cpf_masked',
        'is_test_certificate',
        'certificate_facts',
        'sealed_ulid',
        'sealed_expires_at',
        'window_expires_at',
        'attempts',
        'failure_code',
        'failure_message',
        'queued_at',
        'applied_at',
        'closed_at',
    ];

    /** @var list<string> */
    protected $hidden = ['sealed_ulid', 'consent_ip'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'requested',
        'attempts' => 0,
        'is_test_certificate' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ParticipantSignatureRequestStatus::class,
            'consented_at' => 'datetime',
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'is_test_certificate' => 'boolean',
            'certificate_facts' => 'array',
            'sealed_expires_at' => 'datetime',
            'window_expires_at' => 'datetime',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'applied_at' => 'datetime',
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

    /** @return HasMany<ParticipantSignature, $this> */
    public function signatures(): HasMany
    {
        return $this->hasMany(ParticipantSignature::class, 'participant_signature_request_id');
    }

    /**
     * Nome do titular como o certificado o declara (sem o sufixo ":CPF" do e-CPF).
     */
    public function holderName(): ?string
    {
        $facts = $this->certificate_facts ?? [];
        $name = $facts['holder_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $this->subject_cn;
    }
}
