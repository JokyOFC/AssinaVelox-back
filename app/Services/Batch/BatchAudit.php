<?php

namespace App\Services\Batch;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Services\Batch\Models\BatchSigningSession;
use App\Services\Signing\SignerRequestFacts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Eventos do LOTE, que não pertencem a um envelope (código enviado, confirmado, incorreto).
 *
 * Gravados na organização remetente com `envelope_id` nulo; o ator é o participante a partir
 * do qual o link foi emitido (ator `recipient`). O que acontece com cada documento
 * (`session.started`, `batch.item_*`, `acceptance.recorded`) vai para a trilha do PRÓPRIO
 * envelope por `SignerAudit`. Payload: só ULIDs, canal e contagens — nunca código, token ou
 * e-mail.
 */
final class BatchAudit
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(BatchSigningSession $batch, AuditEventType $type, array $payload = [], ?string $correlationId = null): AuditEvent
    {
        $request = request();

        return AuditEvent::query()->create([
            'organization_id' => $batch->organization_id,
            'envelope_id' => null,
            'recipient_id' => null,
            'actor_type' => ActorType::Recipient,
            'actor_id' => $batch->anchor_recipient_id,
            'event_type' => $type,
            'payload' => ['batch' => $batch->ulid] + $payload,
            'ip_address' => SignerRequestFacts::ip($request),
            'user_agent' => SignerRequestFacts::userAgent($request),
            'correlation_id' => $correlationId ?? (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ]);
    }
}
