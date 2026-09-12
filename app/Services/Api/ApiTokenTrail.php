<?php

namespace App\Services\Api;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Trilha (append-only) da gestão de tokens da API: eventos da ORGANIZAÇÃO (`envelope_id`
 * nulo). Payload só com o ULID, o nome, as abilities e datas — nunca o texto do token, o
 * hash ou o prefixo.
 */
final class ApiTokenTrail
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $payload
     */
    public static function record(ApiToken $token, AuditEventType $type, array $payload = []): AuditEvent
    {
        $request = request();
        $user = Auth::user();

        return AuditEvent::query()->create([
            'organization_id' => $token->organization_id,
            'envelope_id' => null,
            'recipient_id' => null,
            'actor_type' => $user !== null ? ActorType::User : ActorType::System,
            'actor_id' => $user?->getKey(),
            'event_type' => $type,
            'payload' => ['token' => $token->ulid] + $payload,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'correlation_id' => (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ]);
    }
}
