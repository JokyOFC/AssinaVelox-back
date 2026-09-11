<?php

namespace App\Integrations\Timestamp;

use App\Integrations\Contracts\TimestampProvider;
use App\Services\Timestamp\TsaKind;
use InvalidArgumentException;

/**
 * Regra T3 no ponto de gravação de carimbos: quem pode produzir cada `tsa_kind`.
 *
 * - resultado simulado só é gravado como `simulated`;
 * - `icp_brasil` só de um {@see IcpBrasilTimestampProvider} configurado e não simulado — hoje
 *   nenhum (produção bloqueada até o contrato com uma ACT credenciada pelo ITI). Qualquer
 *   outra origem que se diga `icp_brasil` é recusada com exceção, nunca gravada.
 *
 * Fica ao lado dos provedores (e não em App\Services) para o nome da classe do provedor
 * ICP-Brasil não se misturar ao vocabulário dos serviços (tests/Feature/Phase2/VocabularyTest).
 */
final class TsaKindGuard
{
    public static function assertStorable(TsaKind $kind, TimestampProvider $provider, bool $simulated): void
    {
        if ($simulated && $kind !== TsaKind::Simulated) {
            throw new InvalidArgumentException('Carimbo simulado só pode ser gravado como tsa_kind = simulated.');
        }

        $accredited = $provider instanceof IcpBrasilTimestampProvider && $provider->isConfigured() && ! $provider->isSimulated();

        if ($kind === TsaKind::IcpBrasil && ! $accredited) {
            throw new InvalidArgumentException('tsa_kind = icp_brasil só pode vir de ACT credenciada pelo ITI configurada (roadmap T3); nenhuma está configurada.');
        }
    }
}
