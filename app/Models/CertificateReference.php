<?php

namespace App\Models;

use App\Enums\CertificateEnvironment;
use App\Enums\CertificateKind;
use Database\Factories\CertificateReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Referência a um certificado A1. Guarda apenas metadados e o NOME da variável/arquivo com o
 * segredo (secret_ref) — nunca o PFX nem a senha. organization_id nulo = certificado da
 * operadora (Fase 1). Não usa o escopo de organização por isso.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property string $name
 * @property CertificateKind $kind
 * @property CertificateEnvironment $environment
 * @property string $secret_ref
 * @property string|null $subject
 * @property string|null $issuer
 * @property string|null $serial_number
 * @property string|null $fingerprint_sha256
 * @property Carbon|null $not_before
 * @property Carbon|null $not_after
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CertificateReference extends Model
{
    /** @use HasFactory<CertificateReferenceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'name',
        'kind',
        'environment',
        'secret_ref',
        'subject',
        'issuer',
        'serial_number',
        'fingerprint_sha256',
        'not_before',
        'not_after',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => CertificateKind::CompanyA1->value,
        'environment' => CertificateEnvironment::Test->value,
        'is_active' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CertificateKind::class,
            'environment' => CertificateEnvironment::class,
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<VerificationRecord, $this> */
    public function verificationRecords(): HasMany
    {
        return $this->hasMany(VerificationRecord::class);
    }

    public function isPlatformCertificate(): bool
    {
        return $this->organization_id === null;
    }

    public function isValidNow(): bool
    {
        $now = now();

        return $this->is_active
            && ($this->not_before === null || $this->not_before->lte($now))
            && ($this->not_after === null || $this->not_after->gte($now));
    }
}
