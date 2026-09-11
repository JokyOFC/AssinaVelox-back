<?php

namespace App\Services\AdminLog;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Eventos da ORGANIZAÇÃO desta área (etiquetas, exportação de relatório, "acessar como")
 * em `audit_events`, append-only, `envelope_id` nulo — como BillingTrail e PermissionsTrail.
 *
 * Diferença: o ator pode ser informado. Durante o "acessar como" o usuário autenticado é o
 * ALVO, mas quem age é o platform admin; os eventos de suporte registram o admin como ator e
 * `payload.impersonation` com o ULID da sessão.
 *
 * Payload mínimo: ULIDs, nomes curtos e contagens. Nunca e-mail, token, conteúdo de
 * documento ou dado de signatário.
 */
final class OrganizationTrail
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $payload
     */
    public static function record(
        int $organizationId,
        AuditEventType $type,
        array $payload = [],
        ?User $actor = null,
        ?string $correlationId = null,
    ): AuditEvent {
        $request = request();
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;

        return AuditEvent::query()->create([
            'organization_id' => $organizationId,
            'envelope_id' => null,
            'recipient_id' => null,
            'actor_type' => $actor !== null ? ActorType::User : ActorType::System,
            'actor_id' => $actor?->getKey(),
            'event_type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'correlation_id' => $correlationId ?? (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ]);
    }
}
