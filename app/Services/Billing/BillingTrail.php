<?php

namespace App\Services\Billing;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Trilha de auditoria da cobrança — o equivalente de `EnvelopeAudit` para eventos da
 * ORGANIZAÇÃO (`envelope_id` nulo).
 *
 * Payload mínimo, sempre: identificador público do pagamento, código do plano, valor em
 * centavos e moeda, ambiente. **Nunca** access token, chave de webhook, e-mail completo
 * do pagador ou qualquer dado de cartão — o Checkout Pro sequer nos entrega dados de
 * cartão, e nada aqui abre exceção para isso.
 *
 * Quando o evento vem de um job (webhook, comando agendado) não há usuário autenticado:
 * o ator fica `system`, que é o que de fato aconteceu.
 */
final class BillingTrail
{
    /**
     * @param  array<string, scalar|null>  $payload
     */
    public static function record(
        Organization|int $organization,
        AuditEventType $type,
        array $payload = [],
        ?string $correlationId = null,
    ): AuditEvent {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

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
            'correlation_id' => $correlationId ?? (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ]);
    }
}
