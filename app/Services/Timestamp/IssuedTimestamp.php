<?php

namespace App\Services\Timestamp;

/**
 * Carimbo emitido pela TSA da operadora: bytes (resposta e token DER) + metadados públicos.
 * Nada aqui é segredo; o token pode ir para o dossiê.
 */
final readonly class IssuedTimestamp
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $serial,
        public string $genTime,
        public string $policyOid,
        public string $hashAlgorithm,
        public string $imprintHex,
        public string $tokenSha256,
        public ?string $tsaSubject,
        public ?string $tsaCertFingerprint,
        public ?int $accuracyMs,
        public string $tokenDer,
        public string $responseDer,
        public string $environment,
        public bool $testCertificate,
        public ?int $issuanceId,
        public array $raw = [],
    ) {}

    public function kind(): TsaKind
    {
        return TsaKind::Operator;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromTool(array $data, string $tokenDer, string $responseDer, string $environment, ?int $issuanceId): self
    {
        return new self(
            serial: (string) ($data['serial'] ?? ''),
            genTime: (string) ($data['gen_time'] ?? ''),
            policyOid: (string) ($data['policy_oid'] ?? ''),
            hashAlgorithm: (string) ($data['hash_algorithm'] ?? 'sha256'),
            imprintHex: (string) ($data['imprint_hex'] ?? ''),
            tokenSha256: (string) ($data['token_sha256'] ?? hash('sha256', $tokenDer)),
            tsaSubject: isset($data['tsa_subject']) ? (string) $data['tsa_subject'] : null,
            tsaCertFingerprint: isset($data['tsa_cert_fingerprint_sha256']) ? (string) $data['tsa_cert_fingerprint_sha256'] : null,
            accuracyMs: isset($data['accuracy_ms']) ? (int) $data['accuracy_ms'] : null,
            tokenDer: $tokenDer,
            responseDer: $responseDer,
            environment: $environment,
            testCertificate: (bool) ($data['tsa_certificate_test'] ?? true),
            issuanceId: $issuanceId,
            raw: $data,
        );
    }

    /**
     * Metadados para JSON (sem os bytes).
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'tsa_kind' => TsaKind::Operator->value,
            'label' => TsaKind::Operator->label(),
            'serial' => $this->serial,
            'gen_time' => $this->genTime,
            'policy_oid' => $this->policyOid,
            'hash_algorithm' => $this->hashAlgorithm,
            'imprint' => $this->imprintHex,
            'token_sha256' => $this->tokenSha256,
            'tsa_subject' => $this->tsaSubject,
            'tsa_cert_fingerprint' => $this->tsaCertFingerprint,
            'accuracy_ms' => $this->accuracyMs,
            'environment' => $this->environment,
            'test_certificate' => $this->testCertificate,
        ];
    }
}
