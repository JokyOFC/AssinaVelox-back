<?php

namespace App\Integrations\LocalSigner\Dto;

/**
 * `signDigest()` — mesmo formato de `POST /v1/sign` do NexU: valor BRUTO da assinatura em
 * Base64, o algoritmo e o certificado que assinou (Base64 DER).
 */
final readonly class LocalSignerSignature
{
    public function __construct(
        public string $signature,
        public string $signatureAlgorithm,
        public string $certificate,
    ) {}
}
