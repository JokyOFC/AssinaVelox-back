<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Enums\SignatureStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Api\ApiFormat;
use App\Services\Verification\SignatureNarrative;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Envelope na API v1 (docs/fase-2/api-v1.md §5.1). Campos estáveis; identificadores ULID;
 * datas ISO-8601 UTC.
 *
 * Semântica honesta (roadmap T1): `status_label` de um envelope concluído é "Assinado" só
 * quando houve assinatura criptográfica ({@see SignatureNarrative::completedLabel()});
 * `signature_status` é `null` até existir o registro de verificação do arquivo final — "ainda
 * não se sabe" é diferente de `none`.
 *
 * Nunca sai: id interno, e-mail do criador, `finalization_key`, `settings` crus, IP.
 *
 * @mixin Envelope
 */
class EnvelopeResource extends JsonResource
{
    protected bool $detailed = false;

    /**
     * Relações usadas pelo recurso (evita N+1).
     *
     * @return list<string>
     */
    public static function relations(): array
    {
        return ['folder', 'creator', 'recipients', 'verificationRecord'];
    }

    public function detailed(bool $detailed = true): static
    {
        $this->detailed = $detailed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Envelope $envelope */
        $envelope = $this->resource;

        $all = $envelope->relationLoaded('recipients') ? $envelope->recipients : $envelope->recipients()->get();
        // Visualizadores (Fase 2 §2.4) não assinam: fora do progresso "N de M".
        $participants = $all->filter(fn (Recipient $recipient): bool => $recipient->participates())->values();
        $signedCount = $participants->where('status', RecipientStatus::Signed)->count();
        $record = $envelope->relationLoaded('verificationRecord') ? $envelope->verificationRecord : $envelope->verificationRecord()->first();
        $completed = $envelope->status === EnvelopeStatus::Completed;
        $signatureStatus = $completed && $record !== null ? ($record->signature_status ?? SignatureStatus::None) : null;

        $data = [
            'id' => $envelope->ulid,
            'object' => 'envelope',
            'display_code' => $envelope->display_code,
            'title' => $envelope->title,
            'status' => $envelope->status->value,
            'status_label' => $completed
                ? SignatureNarrative::completedLabel($record)
                : $envelope->status->labelWithProgress($signedCount),
            'signing_order' => $envelope->signing_order->value,
            'folder' => $envelope->folder ? ['id' => $envelope->folder->ulid, 'name' => $envelope->folder->name] : null,
            'created_by' => ['name' => $envelope->creator?->name],
            'recipients_count' => $participants->count(),
            'signed_count' => $signedCount,
            'viewers_count' => $all->count() - $participants->count(),
            'verification_code' => $envelope->sent_at !== null ? $envelope->formatted_verification_code : null,
            'signature_status' => $signatureStatus?->value,
            'signature_status_label' => $signatureStatus?->label(),
            'created_at' => ApiFormat::date($envelope->created_at),
            'updated_at' => ApiFormat::date($envelope->updated_at),
            'sent_at' => ApiFormat::date($envelope->sent_at),
            'expires_at' => ApiFormat::date($envelope->expires_at),
            'completed_at' => ApiFormat::date($envelope->completed_at),
            'refused_at' => ApiFormat::date($envelope->refused_at),
            'expired_at' => ApiFormat::date($envelope->expired_at),
            'canceled_at' => ApiFormat::date($envelope->canceled_at),
        ];

        if (! $this->detailed) {
            return $data;
        }

        return $data + [
            'message' => $envelope->message,
            'expiration_days' => $envelope->setting('expiration_days'),
            'send_copy_to_all' => (bool) $envelope->setting('send_copy_to_all', false),
            'cancel_reason' => $envelope->status === EnvelopeStatus::Canceled ? $envelope->setting('cancel_reason') : null,
            'documents' => DocumentResource::listFor($envelope, $request),
            'recipients' => RecipientResource::collection($all)->resolve($request),
            'links' => $this->links($envelope, $completed),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    protected function links(Envelope $envelope, bool $completed): array
    {
        $file = fn (string $type): string => route('api.v1.envelopes.files.show', ['envelope' => $envelope->ulid, 'type' => $type]);

        return [
            'self' => route('api.v1.envelopes.show', ['envelope' => $envelope->ulid]),
            'recipients' => route('api.v1.envelopes.recipients.index', ['envelope' => $envelope->ulid]),
            'fields' => route('api.v1.envelopes.fields.index', ['envelope' => $envelope->ulid]),
            'events' => route('api.v1.envelopes.events.index', ['envelope' => $envelope->ulid]),
            'verification' => $envelope->sent_at !== null ? route('api.v1.envelopes.verification.show', ['envelope' => $envelope->ulid]) : null,
            'original_file' => $file('original'),
            'signed_file' => $completed ? $file('signed') : null,
            'evidence_file' => $completed ? $file('evidence') : null,
        ];
    }
}
