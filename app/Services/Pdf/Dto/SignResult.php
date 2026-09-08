<?php

namespace App\Services\Pdf\Dto;

use App\Enums\CertificateEnvironment;

/**
 * Resultado de `pdftool sign` (PAdES B-B). `timestamp` é sempre null no perfil
 * B-B: não há carimbo do tempo nem LTV. `environment` vem da configuração do
 * certificado (test|production) — certificados de teste nunca são
 * apresentados como ICP-Brasil.
 */
final readonly class SignResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $profile,
        public string $fieldName,
        public string $signerSubject,
        public string $issuer,
        public string $serialHex,
        public string $certFingerprintSha256,
        public ?string $notBefore,
        public ?string $notAfter,
        public string $mdAlgorithm,
        public ?string $timestamp,
        public bool $visible,
        public int $pageCount,
        public string $outputPath,
        public ?CertificateEnvironment $environment = null,
        public ?string $correlationId = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(
        array $data,
        string $outputPath,
        ?CertificateEnvironment $environment = null,
        ?string $correlationId = null,
    ): self {
        return new self(
            profile: (string) ($data['profile'] ?? 'PAdES-B-B'),
            fieldName: (string) ($data['field_name'] ?? ''),
            signerSubject: (string) ($data['signer_subject'] ?? ''),
            issuer: (string) ($data['issuer'] ?? ''),
            serialHex: (string) ($data['serial_hex'] ?? ''),
            certFingerprintSha256: (string) ($data['cert_fingerprint_sha256'] ?? ''),
            notBefore: isset($data['not_before']) ? (string) $data['not_before'] : null,
            notAfter: isset($data['not_after']) ? (string) $data['not_after'] : null,
            mdAlgorithm: (string) ($data['md_algorithm'] ?? 'sha256'),
            timestamp: isset($data['timestamp']) ? (string) $data['timestamp'] : null,
            visible: (bool) ($data['visible'] ?? false),
            pageCount: (int) ($data['page_count'] ?? 0),
            outputPath: $outputPath,
            environment: $environment,
            correlationId: $correlationId,
            raw: $data,
        );
    }

    public function hasTimestamp(): bool
    {
        return $this->timestamp !== null;
    }

    public function isTestCertificate(): bool
    {
        return $this->environment !== CertificateEnvironment::Production;
    }
}
