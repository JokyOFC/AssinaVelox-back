<?php

namespace App\Services\Envelopes;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Gravação da trilha de auditoria a partir das ações do app (RECONCILIACAO §3).
 *
 * Payload mínimo: nunca tokens, senhas, códigos OTP nem e-mails completos quando puderem
 * ser evitados — quem chama é responsável por já entregar o payload reduzido.
 */
final class EnvelopeAudit
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        Envelope $envelope,
        AuditEventType $type,
        array $payload = [],
        ?Recipient $recipient = null,
        ?string $correlationId = null,
    ): AuditEvent {
        $request = request();
        $user = Auth::user();

        return AuditEvent::query()->create([
            'organization_id' => $envelope->organization_id,
            'envelope_id' => $envelope->getKey(),
            'recipient_id' => $recipient?->getKey(),
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
