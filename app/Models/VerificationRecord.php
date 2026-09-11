<?php

namespace App\Models;

use App\Enums\SignatureStatus;
use Database\Factories\VerificationRecordFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Registro público de verificação. Não usa o escopo de organização: a página /verificar é
 * pública e consulta pelo código.
 *
 * @property int $id
 * @property string $code
 * @property int $envelope_id
 * @property int $organization_id
 * @property int|null $final_document_version_id
 * @property string|null $original_sha256
 * @property string|null $sent_sha256
 * @property string|null $consolidated_sha256
 * @property string $final_sha256
 * @property SignatureStatus $signature_status
 * @property string|null $signature_profile
 * @property int|null $certificate_reference_id
 * @property array<string, mixed>|null $validation_result
 * @property Carbon|null $validated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $revoked_at
 * @property-read string $formatted_code
 */
class VerificationRecord extends Model
{
    /** @use HasFactory<VerificationRecordFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'envelope_id',
        'organization_id',
        'final_document_version_id',
        'original_sha256',
        'sent_sha256',
        'consolidated_sha256',
        'final_sha256',
        'signature_status',
        'signature_profile',
        'certificate_reference_id',
        'validation_result',
        'validated_at',
        'revoked_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'signature_status' => SignatureStatus::None->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signature_status' => SignatureStatus::class,
            'validation_result' => 'array',
            'validated_at' => 'datetime',
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function finalVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'final_document_version_id');
    }

    /** @return BelongsTo<CertificateReference, $this> */
    public function certificateReference(): BelongsTo
    {
        return $this->belongsTo(CertificateReference::class);
    }

    /**
     * Resumos publicados por documento (Fase 2 §2.3). Vazio para envelopes finalizados antes
     * da Fase 2 — nesses, as colunas do próprio registro descrevem o documento único.
     *
     * @return HasMany<VerificationRecordDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VerificationRecordDocument::class)->orderBy('position');
    }

    /** @return Attribute<string, never> */
    protected function formattedCode(): Attribute
    {
        return Attribute::get(fn (): string => implode('-', str_split(strtoupper($this->code), 4)));
    }

    /**
     * Normaliza um código digitado pelo usuário (remove hifens/espaços, maiúsculas).
     */
    public static function normalizeCode(string $input): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
