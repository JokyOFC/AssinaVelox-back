<?php

namespace App\Integrations\Timestamp;

use App\Integrations\Contracts\TimestampProvider;
use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Contracts\Config\Repository;

/**
 * Escolhe o adaptador do carimbo ICP-Brasil por `assinavelox.tsa.icp_brasil.driver`:
 *
 * - `disabled` (padrão): {@see IcpBrasilTimestampProvider}, produção bloqueada;
 * - `fake`: {@see FakeIcpBrasilTimestampProvider}, simulador que nunca grava `icp_brasil`.
 *
 * Qualquer outro valor é erro de configuração explícito — nunca um adaptador inventado (T4).
 */
final class IcpBrasilTimestampFactory
{
    public function __construct(private readonly Repository $config) {}

    public function make(): TimestampProvider
    {
        $driver = (string) $this->config->get('assinavelox.tsa.icp_brasil.driver', 'disabled');

        return match ($driver) {
            'disabled' => new IcpBrasilTimestampProvider,
            'fake' => new FakeIcpBrasilTimestampProvider($this->config),
            default => throw new IntegrationException(sprintf('Carimbo ICP-Brasil: o driver "%s" não existe; só há "disabled" e "fake" até o contrato com uma ACT (roadmap §3.6).', $driver)),
        };
    }
}
