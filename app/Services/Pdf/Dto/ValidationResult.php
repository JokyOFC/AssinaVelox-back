<?php

namespace App\Services\Pdf\Dto;

/**
 * Saída de `pdftool validate`. Sem raízes de confiança, `trusted` é false em
 * todas as assinaturas (trust_reason=no_trust_roots_configured); a ferramenta
 * nunca afirma confiança sem cadeia validada. Um PDF sem assinaturas tem
 * signatureCount=0 e allIntact/allValid=false.
 *
 * `allIntact` responde "os bytes COBERTOS pela assinatura foram alterados?" e nada
 * mais. Um PDF com conteúdo acrescentado DEPOIS da revisão assinada continua
 * `intact`, `valid` e até `trusted`: o que muda é `allCovering` (coverage
 * ENTIRE_REVISION em vez de ENTIRE_FILE) e, quando a alteração toca o catálogo,
 * `allDocmdpOk`. Quem quiser afirmar "nada mudou depois da assinatura" precisa dos
 * três, não só do primeiro.
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
        public bool $allCovering = false,
        public bool $allDocmdpOk = false,
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
            // Chave ausente (ferramenta antiga) não vira sucesso: cai na leitura por
            // assinatura, que também exige evidência positiva.
            allCovering: array_key_exists('all_covering', $data)
                ? $data['all_covering'] === true
                : self::everySignature($signatures, static fn (SignatureValidation $s): bool => $s->coverage === 'ENTIRE_FILE'),
            allDocmdpOk: array_key_exists('all_docmdp_ok', $data)
                ? $data['all_docmdp_ok'] === true
                : self::everySignature($signatures, static fn (SignatureValidation $s): bool => $s->docmdpOk !== false),
        );
    }

    /**
     * @param  list<SignatureValidation>  $signatures
     * @param  callable(SignatureValidation): bool  $predicate
     */
    private static function everySignature(array $signatures, callable $predicate): bool
    {
        if ($signatures === []) {
            return false;
        }

        foreach ($signatures as $signature) {
            if (! $predicate($signature)) {
                return false;
            }
        }

        return true;
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
            'all_covering' => $this->allCovering,
            'all_docmdp_ok' => $this->allDocmdpOk,
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
                'docmdp_ok' => $signature->docmdpOk,
                'summary' => $signature->summary,
                'errors' => $signature->errors,
            ], $this->signatures),
        ];
    }
}
