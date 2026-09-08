<?php

namespace Database\Factories;

use App\Enums\SignatureStatus;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VerificationRecord>
 */
class VerificationRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory()->completed(),
            'organization_id' => fn (array $attributes) => self::envelope($attributes)->organization_id,
            'code' => fn (array $attributes) => self::envelope($attributes)->verification_code
                ?? Envelope::generateVerificationCode(),
            'final_document_version_id' => fn (array $attributes) => self::envelope($attributes)->final_document_version_id,
            'original_sha256' => hash('sha256', Str::random(32)),
            'sent_sha256' => hash('sha256', Str::random(32)),
            'consolidated_sha256' => hash('sha256', Str::random(32)),
            'final_sha256' => hash('sha256', Str::random(32)),
            'signature_status' => SignatureStatus::None,
            'signature_profile' => null,
            'certificate_reference_id' => null,
            'validation_result' => null,
            'validated_at' => null,
            'revoked_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function envelope(array $attributes): Envelope
    {
        return Envelope::withoutOrganizationScope()->withTrashed()->whereKey($attributes['envelope_id'])->firstOrFail();
    }

    public function forEnvelope(Envelope $envelope): static
    {
        return $this->state(fn () => [
            'envelope_id' => $envelope->id,
            'organization_id' => $envelope->organization_id,
            'code' => $envelope->verification_code ?? Envelope::generateVerificationCode(),
            'final_document_version_id' => $envelope->final_document_version_id,
        ]);
    }

    public function signedByCompany(?int $certificateReferenceId = null): static
    {
        return $this->state(fn () => [
            'signature_status' => SignatureStatus::CompanyA1,
            'signature_profile' => 'PAdES-B-B',
            'certificate_reference_id' => $certificateReferenceId,
            'validation_result' => ['valid' => true, 'validator' => 'pyhanko', 'environment' => 'test'],
            'validated_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subDay()]);
    }
}
