<?php

namespace App\Models;

use App\Enums\LocalSignerComponent;
use App\Enums\PendingExternalSignatureStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Reserva de revisão para uma assinatura feita FORA do servidor (Fase 3 §3.4, P3-EXT).
 *
 * Guarda o digest entregue, a revisão-base (id + sha256), o prazo e os fatos públicos do
 * certificado anunciado. A revisão pendente (PDF com o espaço reservado) e o estado mínimo do
 * pdftool ficam em arquivos temporários identificados pelo ULID desta linha (fora de
 * `public/`), com o sha256 de cada um aqui. Nunca chave, senha, PIN ou identificador de sessão
 * do token — o servidor não os recebe.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property int $document_id
 * @property int $participant_signature_request_id
 * @property int $base_document_version_id
 * @property string $base_sha256
 * @property string $field_name
 * @property PendingExternalSignatureStatus $status
 * @property string $mode
 * @property LocalSignerComponent $component
 * @property bool $is_simulated
 * @property string $digest_hex
 * @property string $state_sha256
 * @property string $pending_sha256
 * @property int $pending_size
 * @property string $certificate_fingerprint_sha256
 * @property string|null $certificate_subject
 * @property string|null $certificate_issuer
 * @property string|null $certificate_serial
 * @property Carbon|null $certificate_not_before
 * @property Carbon|null $certificate_not_after
 * @property bool $is_test_certificate
 * @property array<string, mixed>|null $certificate_facts
 * @property array<string, mixed>|null $chain_trust
 * @property int|null $reservation_key
 * @property Carbon $expires_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $closed_at
 * @property int $attempts
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int|null $signed_document_version_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PendingExternalSignature extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const MODE_RAW = 'raw';

    public const MODE_CMS = 'cms';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * O digest só sai na resposta da preparação; nunca numa serialização genérica.
     *
     * @var list<string>
     */
    protected $hidden = ['digest_hex', 'state_sha256', 'pending_sha256', 'reservation_key'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
        'is_simulated' => false,
        'is_test_certificate' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PendingExternalSignatureStatus::class,
            'component' => LocalSignerComponent::class,
            'is_simulated' => 'boolean',
            'is_test_certificate' => 'boolean',
            'pending_size' => 'integer',
            'certificate_not_before' => 'datetime',
            'certificate_not_after' => 'datetime',
            'certificate_facts' => 'array',
            'chain_trust' => 'array',
            'reservation_key' => 'integer',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'consumed_at' => 'datetime',
            'closed_at' => 'datetime',
            'attempts' => 'integer',
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

    /** @return BelongsTo<ParticipantSignatureRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ParticipantSignatureRequest::class, 'participant_signature_request_id');
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'base_document_version_id');
    }

    public function isExpired(?Carbon $now = null): bool
    {
        return $this->expires_at->lte($now ?? Carbon::now());
    }
}
