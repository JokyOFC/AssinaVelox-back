<?php

namespace App\Services\Templates;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Template;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Trilha dos modelos (append-only, T7). Payload minimizado: identificadores opacos e
 * contagens — nunca o conteúdo do modelo nem os valores preenchidos (que podem ter CPF,
 * endereço, valores de contrato).
 */
final class TemplateAudit
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        Template $template,
        AuditEventType $type,
        array $payload = [],
        ?Envelope $envelope = null,
    ): AuditEvent {
        $request = request();
        $user = Auth::user();

        return AuditEvent::query()->create([
            'organization_id' => $template->organization_id,
            'envelope_id' => $envelope?->getKey(),
            'recipient_id' => null,
            'actor_type' => $user !== null ? ActorType::User : ActorType::System,
            'actor_id' => $user?->getKey(),
            'event_type' => $type,
            'payload' => ['template' => $template->ulid] + $payload,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'correlation_id' => (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ]);
    }
}
