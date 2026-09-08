<?php

namespace App\Integrations\Contracts;

/**
 * Fase 2/3 — sem implementação. Carimbo do tempo RFC 3161 (TSA) para evoluir o
 * perfil de assinatura de PAdES B-B para B-T/LTV.
 *
 * Enquanto não existir implementação, `timestamp` em SignResult é sempre null e
 * a UI não pode afirmar carimbo do tempo. O pdftool hoje roda sem rede; uma
 * TSA exigirá revisar essa premissa (ver docs/pdf-pipeline.md).
 */
interface TimestampProvider
{
    /**
     * @param  string  $digestHex  hash (hex) do dado a carimbar
     * @return array{token_der_base64: string, tsa: string, genTime: string, serial: string, hash_algorithm: string}
     */
    public function timestamp(string $digestHex, string $hashAlgorithm = 'sha256', ?string $correlationId = null): array;

    public function isConfigured(): bool;

    public function name(): string;
}
