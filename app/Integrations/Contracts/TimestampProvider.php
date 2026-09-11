<?php

namespace App\Integrations\Contracts;

/**
 * Carimbo do tempo RFC 3161 (TSA) para evoluir o perfil de assinatura de PAdES B-B para
 * B-T/LTV (roadmap §2.13, §3.6).
 *
 * Hoje só existe o simulador identificado (App\Integrations\Timestamp\FakeTimestampProvider),
 * que devolve `tsa_kind = simulated` e um token que NÃO é DER nem RFC 3161. Regra T3: só
 * uma ACT credenciada pelo ITI recebe `tsa_kind = icp_brasil`; TSA própria é `operator`,
 * comercial é `commercial`. Enquanto não houver implementação real, `timestamp` em
 * SignResult é sempre null e a UI não pode afirmar carimbo do tempo.
 */
interface TimestampProvider
{
    /**
     * @param  string  $digestHex  hash (hex) do dado a carimbar
     * @return array{token_der_base64: string, tsa: string, genTime: string, serial: string, hash_algorithm: string, tsa_kind: 'simulated'|'operator'|'commercial'|'icp_brasil', simulated: bool}
     */
    public function timestamp(string $digestHex, string $hashAlgorithm = 'sha256', ?string $correlationId = null): array;

    public function isConfigured(): bool;

    public function isSimulated(): bool;

    public function name(): string;
}
