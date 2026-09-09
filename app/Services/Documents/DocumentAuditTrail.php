<?php

namespace App\Services\Documents;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Registro dos eventos de auditoria do pipeline documental (RECONCILIACAO §3).
 *
 * Payload minimizado: nomes de arquivo, tamanhos, hashes e códigos de falha. Nunca
 * tokens, senhas, códigos OTP nem conteúdo do documento. Caminhos no disco também não
 * entram (são detalhe interno e ajudariam quem lê a trilha a montar um alvo).
 */
class DocumentAuditTrail
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Envelope $envelope,
        AuditEventType $type,
        array $payload = [],
        ?User $actor = null,
        ?Request $request = null,
        ?string $correlationId = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'organization_id' => $envelope->organization_id,
            'envelope_id' => $envelope->getKey(),
            'recipient_id' => null,
            'actor_type' => $actor !== null ? ActorType::User : ActorType::System,
            'actor_id' => $actor?->getKey(),
            'event_type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 500),
            'correlation_id' => $correlationId,
        ]);
    }
}
