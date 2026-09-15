<?php

namespace App\Services\HubSpot;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Trilha (append-only) da conexão com o HubSpot: eventos da ORGANIZAÇÃO (`envelope_id` nulo).
 * Payload só com ULID da conexão, portal e escopos — nunca token.
 */
final class HubSpotTrail
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $payload
     */
    public static function record(int $organizationId, AuditEventType $type, array $payload): AuditEvent
    {
        $request = request();
        $user = Auth::user();

        return AuditEvent::query()->create([
            'organization_id' => $organizationId,
            'envelope_id' => null,
            'recipient_id' => null,
            'actor_type' => $user !== null ? ActorType::User : ActorType::System,
            'actor_id' => $user?->getKey(),
            'event_type' => $type,
            'payload' => $payload,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'correlation_id' => (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ]);
    }
}
