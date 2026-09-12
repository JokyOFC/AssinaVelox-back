<?php

namespace App\Services\Webhooks;

use App\Enums\RecipientStatus;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\VerificationRecord;
use App\Models\WebhookEndpoint;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * Corpo JSON das entregas (docs/fase-2/webhooks.md §3). Mínimo e estável:
 *
 *     { "id", "type", "version", "occurred_at", "organization": {"id"}, "data": {...} }
 *
 * Política de privacidade (a mesma da API e da verificação pública):
 *
 *  - só ULIDs públicos — nunca o `id` interno;
 *  - SEM nome, e-mail, telefone ou CPF de participante, SEM título do envelope (pode conter
 *    dado pessoal), SEM motivo de recusa (texto livre), SEM código/PIN/token/senha, SEM imagem
 *    de assinatura ou captura, SEM caminho de arquivo e SEM o código de verificação;
 *  - rótulos honestos (T1): o aceite é "aceite eletrônico"; a abertura é "abertura detectada";
 *    a assinatura criptográfica do arquivo final vem com o MESMO rótulo da verificação
 *    pública, que já mostra os resumos `sent_sha256`/`final_sha256` e o perfil.
 *
 * O resultado é a string JSON exata que será assinada e enviada em todas as tentativas.
 */
final class WebhookPayloadFactory
{
    public const VERSION = 1;

    public function forAuditEvent(
        AuditEvent $event,
        WebhookEventType $type,
        Organization $organization,
        ?Envelope $envelope,
        ?Recipient $recipient,
    ): string {
        $data = [];

        if ($envelope !== null) {
            $data['envelope'] = $this->envelope($envelope);
        }

        if ($recipient !== null && $type->isRecipientEvent()) {
            $data['recipient'] = $this->recipient($recipient, $type);
        }

        if ($type === WebhookEventType::EnvelopeCompleted && $envelope !== null) {
            $data['signature'] = $this->signature($envelope);
        }

        if ($type === WebhookEventType::DocumentProcessingFailed) {
            $data['document'] = $this->failedDocument((array) ($event->payload ?? []));
        }

        return $this->encode($this->wrap($event->ulid, $type, $event->occurred_at, $organization, $data));
    }

    public function ping(string $eventId, Organization $organization, WebhookEndpoint $endpoint): string
    {
        return $this->encode($this->wrap($eventId, WebhookEventType::Ping, Carbon::now(), $organization, [
            'endpoint' => ['id' => $endpoint->ulid],
            'message' => 'Evento de teste da AssinaVelox. Nenhum documento envolvido.',
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function wrap(string $eventId, WebhookEventType $type, ?DateTimeInterface $occurredAt, Organization $organization, array $data): array
    {
        return [
            'id' => $eventId,
            'type' => $type->value,
            'version' => self::VERSION,
            'occurred_at' => self::date($occurredAt ?? Carbon::now()),
            'organization' => ['id' => $organization->ulid],
            'data' => $data === [] ? new stdClass : $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(Envelope $envelope): array
    {
        $recipients = Recipient::withoutOrganizationScope()->where('envelope_id', $envelope->getKey());

        return [
            'id' => $envelope->ulid,
            'code' => $envelope->display_code,
            'status' => $envelope->status->value,
            'status_label' => $envelope->status->label(),
            'signing_order' => $envelope->signing_order->value,
            'sent_at' => self::date($envelope->sent_at),
            'expires_at' => self::date($envelope->expires_at),
            'completed_at' => self::date($envelope->completed_at),
            'refused_at' => self::date($envelope->refused_at),
            'expired_at' => self::date($envelope->expired_at),
            'canceled_at' => self::date($envelope->canceled_at),
            'participants' => [
                'total' => (clone $recipients)->count(),
                'concluded' => (clone $recipients)->where('status', RecipientStatus::Signed->value)->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recipient(Recipient $recipient, WebhookEventType $type): array
    {
        $base = [
            'id' => $recipient->ulid,
            'role' => $recipient->role->value,
            'role_label' => $recipient->role->label(),
            'status' => $recipient->status->value,
            'order' => $recipient->order_index,
        ];

        return $base + match ($type) {
            WebhookEventType::RecipientViewed => [
                'meaning' => 'opened_detected_not_read',
                'meaning_label' => 'Abertura detectada do convite — não é prova de leitura',
            ],
            WebhookEventType::RecipientSigned => [
                'action' => 'electronic_acceptance',
                'action_label' => 'Aceite eletrônico registrado (não é assinatura com certificado)',
            ],
            WebhookEventType::RecipientApproved => [
                'action' => 'approval',
                'action_label' => 'Aprovação registrada',
            ],
            WebhookEventType::RecipientRefused => [
                'action' => 'refusal',
                'action_label' => 'Recusa registrada (o motivo não é enviado)',
            ],
            default => [],
        };
    }

    /**
     * Rótulo honesto do que o arquivo final carrega — o mesmo `SignatureStatus::label()` da
     * verificação pública. Nunca "assinatura digital" genérica.
     *
     * @return array<string, mixed>
     */
    private function signature(Envelope $envelope): array
    {
        /** @var VerificationRecord|null $record */
        $record = VerificationRecord::query()
            ->withoutGlobalScopes()
            ->where('envelope_id', $envelope->getKey())
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();

        if ($record === null) {
            return ['status' => null, 'label' => null, 'profile' => null, 'sent_sha256' => null, 'final_sha256' => null];
        }

        return [
            'status' => $record->signature_status->value,
            'label' => $record->signature_status->label(),
            'profile' => $record->signature_profile,
            'sent_sha256' => $record->sent_sha256,
            'final_sha256' => $record->final_sha256,
        ];
    }

    /**
     * @param  array<string, mixed>  $auditPayload
     * @return array{id: string|null, failure_code: string|null}
     */
    private function failedDocument(array $auditPayload): array
    {
        $id = $auditPayload['document_ulid'] ?? null;
        $code = $auditPayload['failure_code'] ?? null;

        return [
            'id' => is_string($id) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id) === 1 ? $id : null,
            'failure_code' => is_string($code) && preg_match('/^[a-z0-9_.-]{1,60}$/i', $code) === 1 ? $code : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function date(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : Carbon::instance($date)->utc()->toIso8601ZuluString();
    }
}
