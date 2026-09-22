<?php

namespace App\Services\Identity\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Identity\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma tentativa de verificação facial com documento enviada a um provedor externo
 * (`identity_verifications`, Fase 4 §4.1, docs/fase-4/verificacao-facial.md).
 *
 * A linha não tem imagem nem caminho no disco: `captures` cita as fotos da captura simples
 * (ULID, tipo e SHA-256) que foram enviadas, e `provider_result` é o que o adaptador devolveu
 * já limpo. Quem afirma o resultado é o provedor (`provider`); a plataforma só o registra.
 *
 * Mora em `App\Services\Identity\Models` como as demais linhas da área de identidade.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property int|null $signing_session_id
 * @property int|null $signature_acceptance_id
 * @property string $provider
 * @property string|null $provider_verification_id
 * @property string $reference
 * @property string $document_type
 * @property VerificationStatus $status
 * @property string|null $reason_code
 * @property array<string, mixed>|null $provider_result
 * @property list<array{capture_ulid: string, kind: string, sha256: string}>|null $captures
 * @property int $attempt
 * @property Carbon $consented_at
 * @property string $consent_version
 * @property Carbon|null $submitted_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $polled_at
 * @property string|null $correlation_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class IdentityVerification extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    protected $table = 'identity_verifications';

    protected $fillable = [
        'organization_id',
        'envelope_id',
        'recipient_id',
        'signing_session_id',
        'signature_acceptance_id',
        'provider',
        'provider_verification_id',
        'reference',
        'document_type',
        'status',
        'reason_code',
        'provider_result',
        'captures',
        'attempt',
        'consented_at',
        'consent_version',
        'submitted_at',
        'completed_at',
        'polled_at',
        'correlation_id',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'provider_result' => 'array',
            'captures' => 'array',
            'attempt' => 'integer',
            'consented_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'polled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /**
     * @return BelongsTo<Recipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /** Aprovada, reprovada ou expirada: o provedor respondeu de vez e a linha não muda mais. */
    public function isConclusive(): bool
    {
        return $this->status->isConclusive();
    }

    public function isApproved(): bool
    {
        return $this->status === VerificationStatus::Approved;
    }

    /** Na fila ou em análise: nenhuma outra tentativa pode ser aberta enquanto isso. */
    public function isInFlight(): bool
    {
        return $this->status->isInFlight();
    }

    /** O provedor era o simulador identificado: a interface e a evidência rotulam "(simulado)". */
    public function isSimulated(): bool
    {
        return ($this->provider_result['simulated'] ?? false) === true;
    }

    /** Resultado da comparação informado pelo provedor (`true`, `false` ou não informado). */
    public function faceMatch(): ?bool
    {
        $match = $this->provider_result['face_match'] ?? null;

        return is_bool($match) ? $match : null;
    }

    public function faceScore(): ?float
    {
        $score = $this->provider_result['face_score'] ?? null;

        return is_int($score) || is_float($score) ? (float) $score : null;
    }

    /** Mensagem que o adaptador devolveu para a pessoa (já em PT-BR e sem dado sensível), ou null. */
    public function providerMessage(): ?string
    {
        $message = $this->provider_result['message'] ?? null;

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    /**
     * ULIDs das fotos da captura que foram enviadas ao provedor, na ordem gravada.
     *
     * @return list<string>
     */
    public function captureUlids(): array
    {
        $ulids = [];

        foreach ($this->captures ?? [] as $capture) {
            $ulids[] = $capture['capture_ulid'];
        }

        return $ulids;
    }
}
