<?php

namespace App\Http\Controllers\Sso;

use App\Models\SsoConnection;
use App\Services\Sso\SsoFeature;
use App\Services\Sso\SsoProtocol;

/**
 * Rotas PÚBLICAS do login corporativo (volta do OIDC, ACS e metadata do SAML): a conexão vem
 * pelo ULID, sem organização corrente. Flag desligada (global ou do plano), ULID desconhecido
 * ou protocolo errado: 404 igual — a rota não vira oráculo de conexões.
 */
trait ResolvesSsoConnection
{
    protected function resolveConnection(string $ulid, SsoProtocol $protocol): SsoConnection
    {
        $globalOn = $protocol === SsoProtocol::Oidc ? SsoFeature::globalOidc() : SsoFeature::globalSaml();

        abort_unless($globalOn, 404);

        $connection = SsoConnection::withoutOrganizationScope()->where('ulid', $ulid)->first();

        abort_if($connection === null || $connection->protocol !== $protocol, 404);
        abort_unless(SsoFeature::enabledFor($protocol, $connection->organization), 404);

        return $connection;
    }
}
