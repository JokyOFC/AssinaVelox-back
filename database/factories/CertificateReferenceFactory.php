<?php

namespace Database\Factories;

use App\Enums\CertificateEnvironment;
use App\Enums\CertificateKind;
use App\Models\CertificateReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CertificateReference>
 */
class CertificateReferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'name' => 'Certificado A1 de teste (AssinaVelox)',
            'kind' => CertificateKind::CompanyA1,
            'environment' => CertificateEnvironment::Test,
            'secret_ref' => 'SIGNING_CERT_TEST_PFX',
            'subject' => 'CN=AssinaVelox Teste, O=AssinaVelox, C=BR',
            'issuer' => 'CN=AssinaVelox Test CA, O=AssinaVelox, C=BR',
            'serial_number' => strtoupper(Str::random(16)),
            'fingerprint_sha256' => hash('sha256', Str::random(32)),
            'not_before' => now()->subMonth(),
            'not_after' => now()->addYear(),
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function production(): static
    {
        return $this->state(fn () => [
            'environment' => CertificateEnvironment::Production,
            'name' => 'Certificado A1 (produção)',
            'secret_ref' => 'SIGNING_CERT_PFX',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'not_before' => now()->subYears(2),
            'not_after' => now()->subDay(),
        ]);
    }
}
