<?php

namespace App\Integrations\LocalSigner\Dto;

/**
 * `getSigningCertificate()` — mesmo formato de `POST /v1/signing-certificate` do NexU:
 * certificado e cadeia em Base64 (DER), algoritmo da chave, digests aceitos e o
 * `keyHandle` opaco. Nada disso é segredo.
 */
final readonly class LocalSignerCertificate
{
    /**
     * @param  list<string>  $chain  Base64 (DER) de cada certificado da cadeia
     * @param  list<string>  $supportedDigests
     */
    public function __construct(
        public string $certificate,
        public array $chain,
        public string $keyAlgorithm,
        public string $keyHandle,
        public array $supportedDigests,
        public string $preferredDigest,
        public string $fingerprintSha256,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'certificate' => $this->certificate,
            'certificate_chain' => $this->chain,
            'encryption_algorithm' => $this->keyAlgorithm,
            'key_handle' => $this->keyHandle,
            'supported_digests' => $this->supportedDigests,
            'preferred_digest' => $this->preferredDigest,
            'fingerprint_sha256' => $this->fingerprintSha256,
        ];
    }
}
