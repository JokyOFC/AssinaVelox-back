<?php

namespace App\Services\PublicForms;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\PublicForm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Trilha do formulário público (append-only, T7).
 *
 * Payload minimizado: ULIDs, destino, contagens e códigos de motivo. NUNCA o e-mail, o nome,
 * os valores digitados, o token público ou o token de confirmação. Os eventos de gestão são
 * da ORGANIZAÇÃO (`envelope_id` nulo); os de envio são gravados no envelope gerado.
 */
final class PublicFormAudit
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        PublicForm $form,
        AuditEventType $type,
        array $payload = [],
        ?Envelope $envelope = null,
    ): AuditEvent {
        $request = request();
        $user = Auth::user();

        return AuditEvent::query()->create([
            'organization_id' => $form->organization_id,
            'envelope_id' => $envelope?->getKey(),
            'recipient_id' => null,
            'actor_type' => $user !== null ? ActorType::User : ActorType::System,
            'actor_id' => $user?->getKey(),
            'event_type' => $type,
            'payload' => ['form' => $form->ulid] + $payload,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'correlation_id' => (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ]);
    }
}
