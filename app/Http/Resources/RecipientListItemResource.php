<?php

namespace App\Http\Resources;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Recipient;
use App\Support\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Linha da tela "Assinaturas" (ROUTES §2.9 → types/models.ts `RecipientListItem`).
 *
 * Espera `envelope` e (quando houver) `acceptance` carregados. A primeira abertura do
 * convite chega como o atributo virtual `viewed_at_event`, preenchido pelo controller a
 * partir da trilha de auditoria em uma única consulta (sem N+1).
 *
 * @mixin Recipient
 */
class RecipientListItemResource extends JsonResource
{
    public static $wrap = null;

    /** Atributo virtual com o `invitation.opened` mais antigo do signatário. */
    public const VIEWED_AT = 'viewed_at_event';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $envelope = $this->envelope;
        $when = $this->signed_at ?? $this->refused_at ?? $this->viewedAt() ?? $this->last_notified_at ?? $this->created_at;

        return [
            'id' => $this->ulid,
            'envelope_id' => $envelope->ulid,
            'name' => $this->name,
            'initials' => $this->initials,
            'email' => $this->email,
            'role' => $this->role_label,
            'envelope' => [
                'display_code' => $envelope->display_code,
                'title' => $envelope->title,
                'status' => $envelope->status->value,
            ],
            'channel' => 'email',
            'auth_methods' => [$this->auth_method->value],
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'when' => $when?->toIso8601String() ?? '',
            'note' => $this->note(),
            'can_resend' => $this->canResend(),
        ];
    }

    protected function viewedAt(): ?CarbonInterface
    {
        $value = $this->resource->getAttribute(self::VIEWED_AT);

        return $value instanceof CarbonInterface ? $value : null;
    }

    protected function timezone(): string
    {
        $organization = CurrentOrganization::instance()->get();

        return $organization === null ? 'UTC' : $organization->timezone;
    }

    /**
     * Nota curta abaixo da data (ROUTES §2.9 / §6.2).
     */
    protected function note(): string
    {
        $timezone = $this->timezone();
        $acceptance = $this->relationLoaded('acceptance') ? $this->acceptance : null;

        return match ($this->status) {
            RecipientStatus::Signed => $acceptance !== null
                ? 'IP '.($acceptance->ip_address ?? '—').' · '.RecipientResource::userAgentLabel($acceptance->user_agent)
                : 'Aceite eletrônico registrado',
            RecipientStatus::Refused => $this->refusal_reason !== null
                ? 'Motivo: “'.Str::limit($this->refusal_reason, 80).'”'
                : 'Recusou assinar',
            RecipientStatus::Expired => 'Prazo encerrado em '.($this->envelope->expires_at?->setTimezone($timezone)->format('d/m/Y') ?? '—'),
            RecipientStatus::Canceled => 'Documento cancelado',
            RecipientStatus::Viewed => 'Visualizou em '.(($this->viewedAt() ?? $this->last_notified_at)?->setTimezone($timezone)->format('d/m/Y H:i') ?? '—'),
            RecipientStatus::Notified => $this->notification_count > 1
                ? 'Enviado · não visualizou · reenviado às '.($this->last_notified_at?->setTimezone($timezone)->format('H:i') ?? '—')
                : 'Enviado · não visualizou',
            RecipientStatus::Pending => 'Aguarda a vez · '.$this->order_index.'.º na ordem',
        };
    }

    protected function canResend(): bool
    {
        $throttle = (int) config('assinavelox.resend.throttle_minutes', 10);

        return $this->envelope->status === EnvelopeStatus::InProgress
            && $this->status->isPendingSignature()
            && $this->status !== RecipientStatus::Pending
            && ($this->last_notified_at === null || $this->last_notified_at->lte(now()->subMinutes($throttle)));
    }
}
