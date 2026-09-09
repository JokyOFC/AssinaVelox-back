<?php

namespace App\Services\Signing;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Trilha de auditoria das ações do **signatário** (ator `recipient`).
 *
 * Difere de App\Services\Envelopes\EnvelopeAudit porque ali o ator é o usuário autenticado
 * da organização; aqui não há usuário — o ator é o destinatário, identificado pelo id
 * interno do recipient.
 *
 * Regra absoluta: o payload nunca carrega token de convite, token de sessão, token de
 * autorização, código OTP nem o e-mail completo. O que entra é o mínimo que explica o
 * evento para quem lê a trilha depois (docs/fluxo-do-signatario.md §5).
 */
final class SignerAudit
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        Envelope $envelope,
        Recipient $recipient,
        AuditEventType $type,
        array $payload = [],
        ?string $correlationId = null,
    ): AuditEvent {
        return self::write($envelope, $recipient, ActorType::Recipient, $recipient->getKey(), $type, $payload, $correlationId);
    }

    /**
     * Evento provocado pelo signatário mas cujo sujeito é o envelope (encerramento,
     * expiração, finalização): ator `system`, porque a decisão é da plataforma.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function system(
        Envelope $envelope,
        AuditEventType $type,
        array $payload = [],
        ?Recipient $recipient = null,
        ?string $correlationId = null,
    ): AuditEvent {
        return self::write($envelope, $recipient, ActorType::System, null, $type, $payload, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function write(
        Envelope $envelope,
        ?Recipient $recipient,
        ActorType $actorType,
        ?int $actorId,
        AuditEventType $type,
        array $payload,
        ?string $correlationId,
    ): AuditEvent {
        $request = request();

        return AuditEvent::query()->create([
            'organization_id' => $envelope->organization_id,
            'envelope_id' => $envelope->getKey(),
            'recipient_id' => $recipient?->getKey(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event_type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'ip_address' => SignerRequestFacts::ip($request),
            'user_agent' => SignerRequestFacts::userAgent($request),
            'correlation_id' => $correlationId ?? (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ]);
    }
}
