<?php

namespace App\Http\Resources;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Support\IpDisplay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evento da trilha (ROUTES §2.7 `events[]`): título e meta pré-formatados em PT-BR,
 * `kind` derivado do tipo (ROUTES §6.6 / AuditEventType::kind()). Espera `recipient` e
 * `actorUser` carregados quando aplicável.
 *
 * @mixin AuditEvent
 */
class AuditEventResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'type' => $this->event_type->value,
            'kind' => $this->event_type->kind(),
            'title' => $this->title(),
            'meta' => $this->meta(),
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }

    protected function title(): string
    {
        $label = $this->event_type->label();
        $recipientName = $this->recipientName();

        return match ($this->event_type) {
            AuditEventType::InvitationSent,
            AuditEventType::InvitationResent,
            AuditEventType::InvitationOpened,
            AuditEventType::ChallengeSent,
            AuditEventType::ChallengeVerified,
            AuditEventType::ChallengeFailed,
            AuditEventType::SessionStarted,
            AuditEventType::AcceptanceRecorded,
            AuditEventType::RecipientRefused => $recipientName ? "{$label} · {$recipientName}" : $label,
            default => $label,
        };
    }

    protected function meta(): string
    {
        $parts = [];
        $payload = $this->payload ?? [];

        $actor = match ($this->actor_type) {
            ActorType::User => $this->relationLoaded('actorUser') && $this->actorUser ? $this->actorUser->name : 'Usuário',
            ActorType::Recipient => $this->recipientName() ?? 'Signatário',
            ActorType::System => 'Sistema',
        };

        $parts[] = $actor;

        if (! empty($payload['reason'])) {
            $parts[] = 'Motivo: “'.$payload['reason'].'”';
        }

        if (! empty($payload['refusal_reason'])) {
            $parts[] = 'Motivo: “'.$payload['refusal_reason'].'”';
        }

        if (! empty($payload['folder_name'])) {
            $parts[] = 'Pasta: '.$payload['folder_name'];
        }

        if (! empty($payload['type']) && $this->event_type === AuditEventType::EnvelopeDownloaded) {
            $parts[] = 'Arquivo: '.$payload['type'];
        }

        if ($this->ip_address && in_array($this->event_type, [
            AuditEventType::InvitationOpened,
            AuditEventType::ChallengeVerified,
            AuditEventType::AcceptanceRecorded,
            AuditEventType::RecipientRefused,
        ], true)) {
            $ip = $this->maskIp($this->ip_address);

            if ($ip !== null) {
                $parts[] = 'IP '.$ip;
            }
        }

        return implode(' · ', array_filter($parts));
    }

    protected function recipientName(): ?string
    {
        if ($this->relationLoaded('recipient') && $this->recipient) {
            return $this->recipient->name;
        }

        $payload = $this->payload ?? [];

        return isset($payload['recipient_name']) ? (string) $payload['recipient_name'] : null;
    }

    /**
     * Delegado a {@see IpDisplay}: `evidence_show_ip` é da ORGANIZAÇÃO e precisa valer
     * igual na trilha, no card do signatário e na página de evidências.
     */
    protected function maskIp(string $ip): ?string
    {
        return IpDisplay::for($ip, $this->organization);
    }
}
