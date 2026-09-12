<?php

namespace App\Http\Resources\Api;

use App\Enums\ApiAbility;
use App\Models\ApiToken;
use App\Services\Api\ApiFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chave de API para a TELA de Integrações → Chaves (contrato para o agente das telas).
 *
 * Nunca traz o texto do token nem o hash: só o prefixo de exibição (`avk_Ab3d…`). O texto
 * aparece uma única vez, no retorno de App\Services\Api\ApiTokenManager::issue().
 *
 * @mixin ApiToken
 */
class ApiTokenResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApiToken $token */
        $token = $this->resource;

        return [
            'id' => $token->ulid,
            'name' => $token->name,
            'prefix' => $token->token_prefix !== null ? $token->token_prefix.'…' : null,
            'abilities' => array_map(static fn (string $value): array => [
                'value' => $value,
                'label' => ApiAbility::from($value)->label(),
            ], $token->abilityValues()),
            'state' => $token->state(),
            'created_by' => $token->creator?->name,
            'created_at' => ApiFormat::date($token->created_at),
            'last_used_at' => ApiFormat::date($token->last_used_at),
            'last_used_ip' => $token->last_used_ip,
            'expires_at' => ApiFormat::date($token->expires_at),
            'revoked_at' => ApiFormat::date($token->revoked_at),
        ];
    }
}
