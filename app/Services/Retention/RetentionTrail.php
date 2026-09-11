<?php

namespace App\Services\Retention;

use App\Models\RetentionEvent;
use Illuminate\Support\Carbon;

/**
 * Grava a trilha da retenção e da preservação (`retention_events`, append-only).
 *
 * Payload mínimo: ULIDs, contagens, categoria e — só nos bloqueios — o motivo digitado,
 * limitado. Nunca título, nome, e-mail, IP, caminho de arquivo ou conteúdo.
 */
final class RetentionTrail
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        int $organizationId,
        string $type,
        array $payload = [],
        ?int $envelopeId = null,
        ?int $legalHoldId = null,
        ?string $subjectUlid = null,
        ?int $actorUserId = null,
    ): RetentionEvent {
        return RetentionEvent::query()->create([
            'organization_id' => $organizationId,
            'envelope_id' => $envelopeId,
            'legal_hold_id' => $legalHoldId,
            'subject_ulid' => $subjectUlid,
            'event_type' => $type,
            'actor_user_id' => $actorUserId,
            'payload' => $payload === [] ? null : $payload,
            'occurred_at' => Carbon::now(),
        ]);
    }

    /**
     * Grava no máximo um evento deste tipo por assunto por dia: tentativas repetidas (o job
     * diário encontrando o mesmo envelope preservado, um `--dry-run`, uma retentativa) não
     * inflam a trilha.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function recordOncePerDay(
        int $organizationId,
        string $type,
        ?string $subjectUlid,
        array $payload = [],
        ?int $envelopeId = null,
        ?int $legalHoldId = null,
        ?int $actorUserId = null,
    ): ?RetentionEvent {
        $exists = RetentionEvent::withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->where('event_type', $type)
            ->when($subjectUlid !== null, fn ($query) => $query->where('subject_ulid', $subjectUlid), fn ($query) => $query->whereNull('subject_ulid'))
            ->where('occurred_at', '>=', Carbon::now()->startOfDay())
            ->exists();

        if ($exists) {
            return null;
        }

        return self::record($organizationId, $type, $payload, $envelopeId, $legalHoldId, $subjectUlid, $actorUserId);
    }
}
