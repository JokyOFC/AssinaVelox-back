<?php

namespace App\Support;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Trilha (append-only) das mudanças de acesso da organização: funções, times, função de
 * um membro e acesso por pasta. `envelope_id` nulo, como em BillingTrail.
 *
 * Payload mínimo: identificadores públicos (ulid da função/time/pasta, id da membership),
 * nomes curtos e as chaves de permissão. Nunca e-mail, token ou dado pessoal além do id.
 */
final class PermissionsTrail
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $payload
     */
    public static function record(int $organizationId, AuditEventType $type, array $payload = []): AuditEvent
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
            'payload' => $payload === [] ? null : $payload,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'correlation_id' => (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ]);
    }
}
