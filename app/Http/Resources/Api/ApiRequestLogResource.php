<?php

namespace App\Http\Resources\Api;

use App\Models\ApiRequestLog;
use App\Services\Api\ApiFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha da aba "Logs" de Integrações (contrato para o agente das telas). Só metadados.
 *
 * @mixin ApiRequestLog
 */
class ApiRequestLogResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApiRequestLog $log */
        $log = $this->resource;
        $token = $log->relationLoaded('token') ? $log->token : null;

        return [
            'id' => $log->ulid,
            'method' => $log->method,
            'route' => $log->route,
            'path' => $log->path_pattern !== null ? '/'.ltrim($log->path_pattern, '/') : null,
            'status' => $log->status,
            'duration_ms' => $log->duration_ms,
            'correlation_id' => $log->correlation_id,
            'idempotent_replay' => $log->idempotent_replay,
            'token' => $token !== null ? ['id' => $token->ulid, 'name' => $token->name] : null,
            'occurred_at' => ApiFormat::date($log->occurred_at),
        ];
    }
}
