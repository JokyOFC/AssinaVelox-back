<?php

namespace App\Services\Signing\Certificates;

/**
 * Fatos PÚBLICOS de um certificado do participante, conforme `pdftool inspect-cert`.
 *
 * O CPF completo existe só em memória, para a regra de correspondência com o que o próprio
 * participante informou no envelope; ele não entra em `facts()`, em `preview()`, em trilha
 * nem em log. Fora daqui o CPF só circula mascarado (`***.456.789-**`).
 *
 * "ICP-Brasil" nunca é afirmado por esta classe: `declaresIcpBrasil` diz apenas que o
 * certificado DECLARA uma política sob o arco 2.16.76.1.2 — uma alegação do próprio
 * certificado. Certificado de teste é sempre rotulado como teste (T1, T3).
 */
final readonly class CertificateInspection
{
    /**
     * @param  list<string>  $keyUsage
     * @param  list<array{oid: string, name: string}>  $extendedKeyUsage
     * @param  list<string>  $warnings
     * @param  list<string>  $policyOids
     */
    public function __construct(
        public string $fingerprint,
        public string $subject,
        public ?string $subjectCn,
        public string $issuer,
        public ?string $issuerCn,
        public string $serial,
        public ?string $notBefore,
        public ?string $notAfter,
        public ?string $holderName,
        #[\SensitiveParameter] private ?string $holderCpf,
        public ?string $holderCpfMasked,
        public ?string $cpfSource,
        public ?bool $cpfCheckDigitsValid,
        public bool $isTest,
        public bool $selfSigned,
        public bool $declaresIcpBrasil,
        public array $keyUsage,
        public array $extendedKeyUsage,
        public int $chainLength,
        public bool $chainReachesRoot,
        public array $warnings,
        public array $policyOids = [],
        public ?string $keyAlgorithm = null,
        public ?int $keySize = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  saída de `pdftool inspect-cert`
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $holder = is_array($data['holder'] ?? null) ? $data['holder'] : [];
        $icp = is_array($data['icp_brasil'] ?? null) ? $data['icp_brasil'] : [];

        $eku = [];
        foreach ((array) ($data['extended_key_usage'] ?? []) as $item) {
            if (is_array($item)) {
                $eku[] = ['oid' => (string) ($item['oid'] ?? ''), 'name' => (string) ($item['name'] ?? '')];
            }
        }

        return new self(
            fingerprint: strtolower((string) ($data['cert_fingerprint_sha256'] ?? '')),
            subject: (string) ($data['subject'] ?? ''),
            subjectCn: $string($data['subject_cn'] ?? null),
            issuer: (string) ($data['issuer'] ?? ''),
            issuerCn: $string($data['issuer_cn'] ?? null),
            serial: (string) ($data['serial_hex'] ?? ''),
            notBefore: $string($data['not_before'] ?? null),
            notAfter: $string($data['not_after'] ?? null),
            holderName: $string($holder['name'] ?? null),
            holderCpf: $string($holder['cpf'] ?? null),
            holderCpfMasked: $string($holder['cpf_masked'] ?? null),
            cpfSource: $string($holder['cpf_source'] ?? null),
            cpfCheckDigitsValid: isset($holder['cpf_check_digits_valid']) ? (bool) $holder['cpf_check_digits_valid'] : null,
            isTest: (bool) ($data['test_certificate'] ?? false),
            selfSigned: (bool) ($data['self_signed'] ?? false),
            declaresIcpBrasil: (bool) ($icp['declares_icp_brasil_policy'] ?? false),
            keyUsage: array_values(array_map('strval', (array) ($data['key_usage'] ?? []))),
            extendedKeyUsage: $eku,
            chainLength: (int) ($data['chain_length'] ?? 0),
            chainReachesRoot: (bool) ($data['chain_reaches_self_signed_root'] ?? false),
            warnings: array_values(array_map('strval', (array) ($data['warnings'] ?? []))),
            policyOids: array_values(array_map('strval', (array) ($icp['policy_oids'] ?? []))),
            keyAlgorithm: $string($data['key_algorithm'] ?? null),
            keySize: isset($data['key_size']) ? (int) $data['key_size'] : null,
        );
    }

    /**
     * Só dígitos do CPF declarado no certificado — para comparação, nunca para exibição.
     */
    public function holderCpfDigits(): ?string
    {
        return $this->holderCpf;
    }

    /**
     * Rótulo do tipo de certificado. Nunca diz "ICP-Brasil" como fato.
     */
    public function kindLabel(): string
    {
        if ($this->isTest) {
            return 'Certificado de TESTE — não é ICP-Brasil e não tem validade jurídica';
        }

        if ($this->selfSigned) {
            return 'Certificado autoassinado — nenhuma autoridade certificadora atesta o titular';
        }

        return $this->declaresIcpBrasil
            ? 'Certificado A1 que declara política ICP-Brasil (cadeia não validada por esta plataforma)'
            : 'Certificado A1 de autoridade certificadora não identificada como ICP-Brasil';
    }

    /**
     * "Assinado com certificado A1 de {nome} (emitido por {AC})" — roadmap §2.12, aceite.
     */
    public static function signedLabel(?string $holder, ?string $issuerCn, bool $isTest): string
    {
        return sprintf(
            'Assinado com certificado A1 de %s (emitido por %s)%s',
            $holder !== null && $holder !== '' ? $holder : 'titular não identificado',
            $issuerCn !== null && $issuerCn !== '' ? $issuerCn : 'emissor não identificado',
            $isTest ? ' — certificado de TESTE, não é ICP-Brasil' : '',
        );
    }

    /**
     * Mascara qualquer sequência de 11 dígitos (CPF) num texto — por exemplo o CN de um
     * e-CPF, que segue a convenção `NOME DO TITULAR:CPF`. `12345678909` → `***.456.789-**`.
     */
    public static function maskCpfIn(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return (string) preg_replace('/(?<!\d)\d{3}(\d{3})(\d{3})\d{2}(?!\d)/', '***.$1.$2-**', $text);
    }

    /**
     * Mascara o CPF de `signer_subject` em cada assinatura de um `ValidationResult::summary()`.
     * Regra única para tudo que publica ou exporta o resultado da validação (validation_result
     * do registro público, `validacao.json` do dossiê).
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function maskSignatureSummary(array $summary): array
    {
        if (! is_array($summary['signatures'] ?? null)) {
            return $summary;
        }

        $summary['signatures'] = array_map(static function (mixed $signature): mixed {
            if (! is_array($signature)) {
                return $signature;
            }

            foreach (['signer_subject', 'subject'] as $key) {
                if (is_string($signature[$key] ?? null)) {
                    $signature[$key] = self::maskCpfIn($signature[$key]);
                }
            }

            return $signature;
        }, $summary['signatures']);

        return $summary;
    }

    /**
     * Fatos persistíveis em `participant_signature_requests.certificate_facts` (sem CPF completo).
     *
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        return [
            'holder_name' => $this->holderName,
            'cpf_source' => $this->cpfSource,
            'cpf_check_digits_valid' => $this->cpfCheckDigitsValid,
            'self_signed' => $this->selfSigned,
            'declares_icp_brasil_policy' => $this->declaresIcpBrasil,
            'policy_oids' => $this->policyOids,
            'key_usage' => $this->keyUsage,
            'extended_key_usage' => $this->extendedKeyUsage,
            'key_algorithm' => $this->keyAlgorithm,
            'key_size' => $this->keySize,
            'chain_length' => $this->chainLength,
            'chain_reaches_self_signed_root' => $this->chainReachesRoot,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Prévia do certificado para a página do participante (sem CPF completo, sem chave).
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        return [
            'holder_name' => $this->holderName,
            'holder_cpf_masked' => $this->holderCpfMasked,
            'cpf_source' => $this->cpfSource,
            'cpf_confirmed' => false,
            // O CN de um e-CPF carrega o CPF ("NOME:CPF"): sai mascarado também aqui.
            'subject' => self::maskCpfIn($this->subject),
            'subject_cn' => self::maskCpfIn($this->subjectCn),
            'issuer' => $this->issuer,
            'issuer_cn' => $this->issuerCn,
            'serial' => $this->serial,
            'fingerprint_sha256' => $this->fingerprint,
            'valid_from' => $this->notBefore,
            'valid_to' => $this->notAfter,
            'key_usage' => $this->keyUsage,
            'extended_key_usage' => array_map(static fn (array $item): string => $item['name'], $this->extendedKeyUsage),
            'self_signed' => $this->selfSigned,
            'chain_length' => $this->chainLength,
            'declares_icp_brasil_policy' => $this->declaresIcpBrasil,
            'icp_brasil_validated' => false,
            'is_test' => $this->isTest,
            'kind_label' => $this->kindLabel(),
            'label' => self::signedLabel($this->holderName, $this->issuerCn, $this->isTest),
            'warnings' => $this->warnings,
        ];
    }
}
