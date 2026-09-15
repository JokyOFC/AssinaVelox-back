<?php

namespace App\Services\HubSpot;

use App\Integrations\HubSpot\HubSpotClient;
use App\Models\HubSpotActionExecution;
use App\Models\HubSpotConnection;
use App\Models\Organization;
use App\Services\CloudImport\Http\ConnectorFailure;
use Illuminate\Support\Carbon;

/**
 * Atualização do negócio/contato no HubSpot quando o envelope muda de estado
 * (docs/fase-3/conectores.md §5.3).
 *
 * DECISÃO: chamada DIRETA à API do CRM (`PATCH /crm/v3/objects/{tipo}/{id}` gravando
 * `hubspot.status_property`), e não pelo motor de webhooks de saída. Os webhooks de saída
 * entregam o NOSSO payload assinado a uma URL do cliente; o HubSpot exige o formato dele e um
 * Bearer OAuth por organização. A chamada passa pela MESMA proteção contra SSRF (ConnectorHttp,
 * hosts `api.hubapi.com`) e roda em fila, com retentativa só para falha transitória (rede,
 * 429, 5xx). 4xx do HubSpot (propriedade inexistente, objeto apagado) encerra como `failed`.
 */
final class HubSpotObjectSync
{
    public function __construct(
        private readonly HubSpotClient $client,
        private readonly HubSpotConnections $connections,
    ) {}

    /**
     * @throws ConnectorFailure falha transitória: o job tenta de novo
     */
    public function push(int $executionId, string $status): void
    {
        /** @var HubSpotActionExecution|null $execution */
        $execution = HubSpotActionExecution::withoutOrganizationScope()->find($executionId);

        if ($execution === null) {
            return;
        }

        $segment = HubSpotClient::crmObjectSegment($execution->object_type);

        if ($segment === null || ! ctype_digit((string) $execution->object_id)) {
            $execution->forceFill(['sync_status' => HubSpotActionExecution::SYNC_NOT_APPLICABLE])->save();

            return;
        }

        $organization = Organization::query()->find($execution->organization_id);

        if ($organization === null || ! HubSpotFeature::enabled($organization)) {
            $this->fail($execution, 'feature_disabled');

            return;
        }

        /** @var HubSpotConnection|null $connection */
        $connection = HubSpotConnection::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->where('portal_id', $execution->portal_id)
            ->first();

        if ($connection === null) {
            $this->fail($execution, 'not_connected');

            return;
        }

        try {
            $token = $this->connections->accessToken($connection);
        } catch (HubSpotException $exception) {
            $this->fail($execution, $exception->errorCode);

            return;
        }

        $property = (string) config('assinavelox.hubspot.status_property', 'assinavelox_status');

        try {
            $this->client->updateObject($token, $segment, (string) $execution->object_id, [$property => $status]);
        } catch (ConnectorFailure $failure) {
            $execution->forceFill([
                'sync_attempts' => $execution->sync_attempts + 1,
                'sync_error_code' => mb_substr($failure->errorCode, 0, 64),
            ])->save();

            $transient = $failure->httpStatus === null || $failure->httpStatus === 429 || $failure->httpStatus >= 500;

            if ($transient) {
                throw $failure;
            }

            $this->fail($execution, $failure->errorCode);

            return;
        }

        $execution->forceFill([
            'sync_status' => HubSpotActionExecution::SYNC_SYNCED,
            'synced_value' => $status,
            'synced_at' => Carbon::now(),
            'sync_attempts' => $execution->sync_attempts + 1,
            'sync_error_code' => null,
        ])->save();
    }

    public function fail(HubSpotActionExecution $execution, string $code): void
    {
        $execution->forceFill([
            'sync_status' => HubSpotActionExecution::SYNC_FAILED,
            'sync_error_code' => mb_substr($code, 0, 64),
        ])->save();
    }
}
