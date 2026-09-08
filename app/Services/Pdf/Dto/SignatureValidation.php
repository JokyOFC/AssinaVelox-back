<?php

namespace App\Services\Pdf\Dto;

/**
 * Uma assinatura conforme `pdftool validate`. `trusted` só é true com cadeia
 * validada até uma raiz configurada; revogação nunca é verificada
 * (`revocation` = not_checked).
 */
final readonly class SignatureValidation
{
    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $fieldName,
        public bool $intact,
        public bool $valid,
        public bool $trusted,
        public ?string $trustReason,
        public ?string $signerSubject,
        public ?string $issuer,
        public ?string $serialHex,
        public ?string $certFingerprintSha256,
        public ?string $notBefore,
        public ?string $notAfter,
        public ?string $signingTime,
        public ?string $mdAlgorithm,
        public ?string $subfilter,
        public ?string $coverage,
        public ?string $modificationLevel,
        public ?bool $docmdpOk,
        public string $revocation,
        public ?string $summary,
        public array $errors,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string => isset($data[$key]) ? (string) $data[$key] : null;

        return new self(
            fieldName: (string) ($data['field_name'] ?? ''),
            intact: (bool) ($data['intact'] ?? false),
            valid: (bool) ($data['valid'] ?? false),
            trusted: (bool) ($data['trusted'] ?? false),
            trustReason: $string('trust_reason'),
            signerSubject: $string('signer_subject'),
            issuer: $string('issuer'),
            serialHex: $string('serial_hex'),
            certFingerprintSha256: $string('cert_fingerprint_sha256'),
            notBefore: $string('not_before'),
            notAfter: $string('not_after'),
            signingTime: $string('signing_time'),
            mdAlgorithm: $string('md_algorithm'),
            subfilter: $string('subfilter'),
            coverage: $string('coverage'),
            modificationLevel: $string('modification_level'),
            docmdpOk: isset($data['docmdp_ok']) ? (bool) $data['docmdp_ok'] : null,
            revocation: (string) ($data['revocation'] ?? 'not_checked'),
            summary: $string('summary'),
            errors: array_values(array_map('strval', (array) ($data['errors'] ?? []))),
            raw: $data,
        );
    }
}
