<?php

namespace App\Http\Resources;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Verification\SignatureNarrative;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EnvelopeListItem (ROUTES §2, com display_code da RECONCILIACAO). Espera as relações
 * folder, creator, recipients e document carregadas (eager) para evitar N+1.
 *
 * @mixin Envelope
 */
class EnvelopeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $recipients = $this->relationLoaded('recipients') ? $this->recipients : $this->recipients()->get();
        $signedCount = $recipients->where('status', RecipientStatus::Signed)->count();
        $document = $this->relationLoaded('document') ? $this->document : null;

        return [
            'id' => $this->ulid,
            'display_code' => $this->display_code,
            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->statusLabel($signedCount),
            'folder' => $this->folder ? ['id' => $this->folder->ulid, 'name' => $this->folder->name] : null,
            'document' => $document ? [
                'pages' => $document->page_count,
                'mime' => $document->isReady() ? 'application/pdf' : null,
                'original_name' => $document->original_filename,
            ] : null,
            'recipients' => $recipients->take(5)->map(fn (Recipient $recipient): array => [
                'id' => $recipient->ulid,
                'name' => $recipient->name,
                'initials' => $recipient->initials,
                'status' => $recipient->status->value,
            ])->values()->all(),
            'recipients_count' => $recipients->count(),
            'signed_count' => $signedCount,
            'creator' => UserRefResource::ref($this->creator) ?? ['id' => '', 'name' => '—', 'initials' => '—'],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'can' => [
                'view' => $user?->can('view', $this->resource) ?? false,
                'update' => ($user?->can('update', $this->resource) ?? false) && $this->status->isDraftLike(),
                'cancel' => ($user?->can('cancel', $this->resource) ?? false) && $this->status->isCancelable(),
                'delete' => ($user?->can('delete', $this->resource) ?? false) && $this->status->isDraftLike(),
                'download_signed' => ($user?->can('view', $this->resource) ?? false) && $this->status === EnvelopeStatus::Completed,
            ],
        ];
    }

    /**
     * "Assinado" só quando houve assinatura criptográfica da operadora; concluído sem ela é
     * "Concluído" ({@see SignatureNarrative::completedLabel()}). Fora de `completed` vale o
     * rótulo do enum.
     */
    protected function statusLabel(int $signedCount): string
    {
        if ($this->status !== EnvelopeStatus::Completed) {
            return $this->status->labelWithProgress($signedCount);
        }

        $record = $this->relationLoaded('verificationRecord')
            ? $this->verificationRecord
            : $this->verificationRecord()->first();

        return SignatureNarrative::completedLabel($record);
    }
}
