<?php

namespace App\Integrations\Timestamp;

use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Contracts\TimestampProvider;
use Illuminate\Contracts\Config\Repository;

/**
 * Simulador IDENTIFICADO do contrato ICP-Brasil (roadmap §3.6), para exercitar o fluxo sem
 * contrato com ACT.
 *
 * - `tsa_kind` é SEMPRE `simulated` e `simulated` é true — NUNCA `icp_brasil`, mesmo sendo o
 *   dublê do provedor ICP-Brasil (T3). O serviço que grava carimbos também recusa;
 * - o token é o JSON do {@see FakeTimestampProvider} (não é DER, não passa em
 *   `openssl ts -verify`);
 * - só funciona com `assinavelox.channels.allow_simulated` (fora de produção).
 */
final class FakeIcpBrasilTimestampProvider implements TimestampProvider
{
    public const NAME = 'act_icp_brasil_simulada';

    public const TSA_LABEL = 'ACT ICP-Brasil SIMULADA — não é carimbo ICP-Brasil, sem valor jurídico';

    public function __construct(private readonly Repository $config) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return (bool) $this->config->get('assinavelox.channels.allow_simulated', false);
    }

    public function timestamp(string $digestHex, string $hashAlgorithm = 'sha256', ?string $correlationId = null): array
    {
        if (! $this->isConfigured()) {
            throw new ProviderDisabledException(self::NAME, ['O simulador de ACT ICP-Brasil só funciona fora de produção.']);
        }

        $result = (new FakeTimestampProvider($this->config))->timestamp($digestHex, $hashAlgorithm, $correlationId);

        return [
            ...$result,
            'tsa' => self::TSA_LABEL,
            'tsa_kind' => 'simulated',
            'simulated' => true,
        ];
    }
}
