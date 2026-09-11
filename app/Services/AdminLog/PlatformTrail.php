<?php

namespace App\Services\AdminLog;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Grava em `platform_audit_events` (append-only) as ações da equipe da plataforma.
 * Payload mínimo: motivo digitado pelo admin, ULIDs e rótulos — nunca senha, token ou
 * conteúdo de documento.
 */
final class PlatformTrail
{
    public const TARGET_USER = 'user';

    public const TARGET_ORGANIZATION = 'organization';

    /**
     * @param  array<string, scalar|list<scalar>|null>  $payload
     */
    public static function record(
        PlatformAction $action,
        ?User $actor,
        ?string $targetType = null,
        ?int $targetId = null,
        ?int $organizationId = null,
        array $payload = [],
        ?string $correlationId = null,
    ): PlatformAuditEvent {
        $request = request();

        return PlatformAuditEvent::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'organization_id' => $organizationId,
            'payload' => $payload === [] ? null : $payload,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'correlation_id' => $correlationId ?? (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ]);
    }
}
