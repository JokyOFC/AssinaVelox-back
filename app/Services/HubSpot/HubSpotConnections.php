<?php

namespace App\Services\HubSpot;

use App\Enums\AuditEventType;
use App\Integrations\HubSpot\HubSpotClient;
use App\Integrations\HubSpot\HubSpotTokens;
use App\Models\HubSpotConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\CloudImport\Http\ConnectorFailure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Conexão da organização com o HubSpot (docs/fase-3/conectores.md §5.1 e §5.4).
 *
 *  - uma conexão por organização, um portal por conexão, e um portal NUNCA em duas
 *    organizações (é por ele que a ação de workflow encontra a organização — isolamento);
 *  - tokens gravados cifrados; o access token (30 min) é renovado com o refresh token quando
 *    falta menos de 1 minuto, sob lock por conexão (dois jobs não renovam ao mesmo tempo e não
 *    queimam o refresh token um do outro);
 *  - renovação recusada pelo HubSpot (400/401) marca a conexão `error`: é preciso conectar de
 *    novo. Nada é tentado em loop.
 */
final class HubSpotConnections
{
    public function __construct(private readonly HubSpotClient $client) {}

    public static function forOrganization(Organization $organization): ?HubSpotConnection
    {
        return HubSpotConnection::forOrganization($organization)->first();
    }

    /**
     * @throws HubSpotException|ConnectorFailure
     */
    public function connect(Organization $organization, User $user, HubSpotTokens $tokens): HubSpotConnection
    {
        $portalId = $tokens->portalId ?? $this->client->portalId($tokens->accessToken());

        if ($portalId === null) {
            throw new HubSpotException('portal_unknown', 'Não foi possível identificar a conta do HubSpot. Tente conectar de novo.');
        }

        if ($tokens->refreshToken() === null) {
            throw new HubSpotException('refresh_token_missing', 'O HubSpot não devolveu a autorização completa. Tente conectar de novo.');
        }

        /** @var array{refresh: string|null, portal: int|string|null} $previous */
        $previous = ['refresh' => null, 'portal' => null];

        $connection = DB::transaction(function () use ($organization, $user, $tokens, $portalId, &$previous): HubSpotConnection {
            $taken = HubSpotConnection::withoutOrganizationScope()
                ->where('portal_id', $portalId)
                ->where('organization_id', '!=', $organization->getKey())
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw new HubSpotException(
                    'portal_in_use',
                    'Esta conta do HubSpot já está conectada a outra organização do AssinaVelox. Desconecte lá antes de conectar aqui.',
                );
            }

            /** @var HubSpotConnection $connection */
            $connection = HubSpotConnection::withoutOrganizationScope()->firstOrNew(['organization_id' => $organization->getKey()]);
            $previousPortal = $connection->exists ? $connection->portal_id : null;
            $previousRefresh = $connection->exists ? $connection->refresh_token : null;
            $previous['refresh'] = is_string($previousRefresh) && $previousRefresh !== '' && $previousRefresh !== $tokens->refreshToken()
                ? $previousRefresh
                : null;
            $previous['portal'] = $previousPortal;
            $connection->forceFill([
                'organization_id' => $organization->getKey(),
                'portal_id' => $portalId,
                'access_token' => $tokens->accessToken(),
                'refresh_token' => $tokens->refreshToken(),
                'token_expires_at' => Carbon::now()->addSeconds(max(60, $tokens->expiresIn)),
                'scopes' => $tokens->scopes,
                'status' => HubSpotConnection::STATUS_ACTIVE,
                'last_error_code' => null,
                'connected_by_user_id' => $user->getKey(),
                'connected_at' => Carbon::now(),
                'last_refreshed_at' => null,
            ])->save();

            HubSpotTrail::record((int) $organization->getKey(), AuditEventType::HubSpotConnected, [
                'connection' => $connection->ulid,
                'portal_id' => $portalId,
                'scopes' => array_slice($tokens->scopes, 0, 20),
                ...($previousPortal !== null ? ['reconnected' => true, 'previous_portal_id' => $previousPortal] : []),
            ]);

            return $connection;
        });

        // Reconexão: o par anterior sai do banco e é revogado no HubSpot (melhor esforço, como em
        // disconnect()). O refresh token do HubSpot não vence sozinho; sem isto ele continuaria
        // válido lá sem que ninguém pudesse revogá-lo pela tela (revisão adversarial da onda G).
        if (is_string($previous['refresh'])) {
            $this->client->revoke($previous['refresh']);
        }

        return $connection;
    }

    public function disconnect(HubSpotConnection $connection): void
    {
        $refresh = $connection->refresh_token;

        if (is_string($refresh) && $refresh !== '') {
            $this->client->revoke($refresh);
        }

        $payload = ['connection' => $connection->ulid, 'portal_id' => $connection->portal_id];
        $organizationId = (int) $connection->organization_id;

        $connection->delete();

        HubSpotTrail::record($organizationId, AuditEventType::HubSpotDisconnected, $payload);
    }

    /**
     * Access token válido por pelo menos mais 1 minuto (renova se preciso).
     *
     * @throws HubSpotException|ConnectorFailure
     */
    public function accessToken(HubSpotConnection $connection): string
    {
        if (self::fresh($connection)) {
            return (string) $connection->access_token;
        }

        return Cache::lock('hubspot-token-refresh:'.$connection->getKey(), 30)->block(15, function () use ($connection): string {
            $connection->refresh();

            if (self::fresh($connection)) {
                return (string) $connection->access_token;
            }

            if (! $connection->isActive()) {
                throw new HubSpotException('reconnect_required', 'A conexão com o HubSpot precisa ser refeita.');
            }

            try {
                $tokens = $this->client->refresh((string) $connection->refresh_token);
            } catch (ConnectorFailure $failure) {
                if (in_array($failure->httpStatus, [400, 401, 403], true)) {
                    $connection->forceFill([
                        'status' => HubSpotConnection::STATUS_ERROR,
                        'last_error_code' => 'refresh_rejected',
                    ])->save();

                    throw new HubSpotException('reconnect_required', 'O HubSpot recusou a renovação do acesso. Conecte de novo.');
                }

                throw $failure;
            }

            $connection->forceFill([
                'access_token' => $tokens->accessToken(),
                // O HubSpot pode ou não girar o refresh token; sem um novo, o atual continua.
                'refresh_token' => $tokens->refreshToken() ?? $connection->refresh_token,
                'token_expires_at' => Carbon::now()->addSeconds(max(60, $tokens->expiresIn)),
                'last_refreshed_at' => Carbon::now(),
                'status' => HubSpotConnection::STATUS_ACTIVE,
                'last_error_code' => null,
            ])->save();

            return $tokens->accessToken();
        });
    }

    private static function fresh(HubSpotConnection $connection): bool
    {
        return is_string($connection->access_token)
            && $connection->access_token !== ''
            && $connection->token_expires_at !== null
            && $connection->token_expires_at->gt(Carbon::now()->addSeconds(60));
    }
}
