<?php

namespace App\Services\Sso;

use App\Enums\AuditEventType;
use App\Models\SsoConnection;
use App\Models\User;
use App\Services\AdminLog\OrganizationTrail;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trilha do login corporativo em `audit_events` (append-only, `envelope_id` nulo — T7).
 *
 * Payload mínimo: ULIDs, protocolo, DOMÍNIO do e-mail (nunca o e-mail completo), códigos de
 * motivo e booleanos. Nunca token, código, assertion, claim bruta, segredo ou certificado.
 */
final class SsoTrail
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $payload
     */
    public static function record(int $organizationId, AuditEventType $type, array $payload = [], ?User $actor = null): void
    {
        try {
            OrganizationTrail::record($organizationId, $type, $payload, $actor);
        } catch (Throwable $exception) {
            // A trilha nunca derruba o login; o motivo (sem payload) fica no log.
            Log::warning('sso.trail_failed', ['event' => $type->value, 'error' => $exception::class]);
        }
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $extra
     * @return array<string, scalar|list<scalar>|null>
     */
    public static function connectionPayload(SsoConnection $connection, array $extra = []): array
    {
        return [
            'connection' => $connection->ulid,
            'protocol' => $connection->protocol->value,
            ...$extra,
        ];
    }
}
