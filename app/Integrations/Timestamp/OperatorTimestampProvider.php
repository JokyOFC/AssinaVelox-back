<?php

namespace App\Integrations\Timestamp;

use App\Integrations\Contracts\TimestampProvider;
use App\Services\Timestamp\IssuedTimestamp;
use App\Services\Timestamp\OperatorTsa;
use App\Services\Timestamp\TsaKind;

/**
 * Provedor `operator` do contrato TimestampProvider: a TSA RFC 3161 da própria AssinaVelox
 * (roadmap §2.13).
 *
 * - `tsa_kind` é SEMPRE `operator` — nunca `icp_brasil` (T3). Rótulo: {@see self::LABEL};
 * - `simulated` é false: o token é RFC 3161 de verdade (DER, passa em `openssl ts -verify`
 *   contra a raiz da AC interna). Com a TSA de TESTE (`tsa:generate-test`) o token é real,
 *   mas o certificado é de teste — {@see IssuedTimestamp::$testCertificate};
 * - disponível só com a flag `operator_tsa` e a configuração completa.
 *
 * Não é registrado no container como implementação padrão de TimestampProvider (o binding
 * está no IntegrationsServiceProvider, fora da área do K-TSA): quem precisa injeta esta
 * classe diretamente.
 */
final class OperatorTimestampProvider implements TimestampProvider
{
    public const NAME = 'tsa_operadora';

    public const LABEL = TsaKind::OPERATOR_LABEL;

    public function __construct(private readonly OperatorTsa $tsa) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return $this->tsa->isAvailable();
    }

    public function timestamp(string $digestHex, string $hashAlgorithm = 'sha256', ?string $correlationId = null): array
    {
        $issued = $this->issue($digestHex, $hashAlgorithm, $correlationId);

        return [
            'token_der_base64' => base64_encode($issued->tokenDer),
            'tsa' => (string) ($issued->tsaSubject ?? self::LABEL),
            'genTime' => $issued->genTime,
            'serial' => $issued->serial,
            'hash_algorithm' => $issued->hashAlgorithm,
            'tsa_kind' => TsaKind::Operator->value,
            'simulated' => false,
        ];
    }

    /**
     * Versão rica (bytes + metadados), para quem guarda o carimbo.
     */
    public function issue(string $digestHex, string $hashAlgorithm = 'sha256', ?string $correlationId = null, string $purpose = 'provider', ?int $organizationId = null): IssuedTimestamp
    {
        return $this->tsa->stampDigest($digestHex, $hashAlgorithm, $purpose, $organizationId, $correlationId);
    }
}
