<?php

namespace App\Services\Pdf\Dto;

/**
 * Saída de `pdftool validate`. Sem raízes de confiança, `trusted` é false em
 * todas as assinaturas (trust_reason=no_trust_roots_configured); a ferramenta
 * nunca afirma confiança sem cadeia validada. Um PDF sem assinaturas tem
 * signatureCount=0 e allIntact/allValid=false.
 */
final readonly class ValidationResult
{
    /**
     * @param  list<SignatureValidation>  $signatures
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $signatureCount,
        public bool $allIntact,
        public bool $allValid,
        public int $trustRootsConfigured,
        public string $revocation,
        public array $signatures,
        public ?string $correlationId = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $correlationId = null): self
    {
        $signatures = [];
        foreach ((array) ($data['signatures'] ?? []) as $signature) {
            if (is_array($signature)) {
                $signatures[] = SignatureValidation::fromArray($signature);
            }
        }

        return new self(
            signatureCount: (int) ($data['signature_count'] ?? count($signatures)),
            allIntact: (bool) ($data['all_intact'] ?? false),
            allValid: (bool) ($data['all_valid'] ?? false),
            trustRootsConfigured: (int) ($data['trust_roots_configured'] ?? 0),
            revocation: (string) ($data['revocation'] ?? 'not_checked'),
            signatures: $signatures,
            correlationId: $correlationId,
            raw: $data,
        );
    }

    public function allTrusted(): bool
    {
        if ($this->signatures === []) {
            return false;
        }

        foreach ($this->signatures as $signature) {
            if (! $signature->trusted) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resumo técnico persistível em verification_records.validation_result
     * (sem caminhos locais).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'signature_count' => $this->signatureCount,
            'all_intact' => $this->allIntact,
            'all_valid' => $this->allValid,
            'all_trusted' => $this->allTrusted(),
            'trust_roots_configured' => $this->trustRootsConfigured,
            'revocation' => $this->revocation,
            'signatures' => array_map(static fn (SignatureValidation $signature): array => [
                'field_name' => $signature->fieldName,
                'intact' => $signature->intact,
                'valid' => $signature->valid,
                'trusted' => $signature->trusted,
                'trust_reason' => $signature->trustReason,
                'signer_subject' => $signature->signerSubject,
                'issuer' => $signature->issuer,
                'serial_hex' => $signature->serialHex,
                'cert_fingerprint_sha256' => $signature->certFingerprintSha256,
                'signing_time' => $signature->signingTime,
                'md_algorithm' => $signature->mdAlgorithm,
                'subfilter' => $signature->subfilter,
                'coverage' => $signature->coverage,
                'modification_level' => $signature->modificationLevel,
                'summary' => $signature->summary,
                'errors' => $signature->errors,
            ], $this->signatures),
        ];
    }
}
