<?php

namespace App\Http\Resources;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Services\Verification\SignatureNarrative;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Envelope da página de detalhe (ROUTES §2.7 → types/models.ts `Envelope`).
 * Espera folder, creator, recipients, document.currentVersion e finalVersion carregados.
 *
 * @mixin Envelope
 */
class EnvelopeDetailResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $signedCount = $this->recipients->where('status', RecipientStatus::Signed)->count();
        $document = $this->document;
        $originalVersion = $document->currentVersion ?? $document?->originalVersion;
        $finalVersion = $this->finalVersion;
        $completed = $this->status === EnvelopeStatus::Completed;
        $isDraftLike = $this->status->isDraftLike();
        $inProgress = $this->status === EnvelopeStatus::InProgress;
        $canUpdate = $user?->can('update', $this->resource) ?? false;
        $canView = $user?->can('view', $this->resource) ?? false;
        $timezone = $this->organization->timezone;
        $record = $this->verificationRecord;
        $signature = $record !== null && $completed
            ? SignatureNarrative::for($this->resource, $record)
            : ['status' => null, 'certificate' => null];

        return [
            'id' => $this->ulid,
            'display_code' => $this->display_code,
            'verification_code' => $this->formatted_verification_code,
            'title' => $this->title,
            'status' => $this->status->value,
            /*
             * Concluído sem assinatura criptográfica não é "Assinado": ver
             * {@see SignatureNarrative::completedLabel()}.
             */
            'status_label' => $completed
                ? SignatureNarrative::completedLabel($record)
                : $this->status->labelWithProgress($signedCount),
            'signed_count' => $signedCount,
            'recipients_count' => $this->recipients->count(),
            'folder' => $this->folder ? ['id' => $this->folder->ulid, 'name' => $this->folder->name] : null,
            'creator' => UserRefResource::ref($this->creator) ?? ['id' => '', 'name' => '—', 'initials' => '—'],
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expires_label' => $this->expiresLabel($timezone),
            'expiring_soon' => $inProgress && $this->expires_at !== null && $this->expires_at->isFuture() && $this->expires_at->diffInHours(now()) < 72,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'cancel_reason' => $this->setting('cancel_reason'),
            'signing_order' => $this->signing_order->value,
            'message' => $this->message,
            'send_copy_to_all' => (bool) $this->setting('send_copy_to_all', false),
            'document' => $document ? [
                'original_name' => $document->original_filename,
                'size_bytes' => (int) ($originalVersion->size_bytes ?? 0),
                'pages' => (int) ($document->page_count ?? $originalVersion->page_count ?? 0),
                'sha256_original' => (string) ($document->originalVersion->sha256 ?? $originalVersion->sha256 ?? ''),
                'sha256_signed' => $finalVersion?->sha256,
                // Exibição no PDF.js: sempre a versão **exibível** (`envelopes.document.preview`).
                // `envelopes.download?type=original` entrega os bytes como enviados — para DOCX
                // e imagem isso não é PDF e o PDF.js não abriria (docs/preparacao-documental.md).
                'pdf_url' => route('envelopes.document.preview', ['envelope' => $this->ulid]),
            ] : null,
            'downloads' => [
                'original' => $document && $canView ? route('envelopes.download', ['envelope' => $this->ulid, 'type' => 'original']) : null,
                'signed' => $completed && $canView ? route('envelopes.download', ['envelope' => $this->ulid, 'type' => 'signed']) : null,
                'evidence' => $completed && $canView ? route('envelopes.download', ['envelope' => $this->ulid, 'type' => 'evidence']) : null,
            ],
            /*
             * Situação da assinatura criptográfica (arquitetura §2). `null` enquanto não houver
             * registro de verificação: a tela precisa distinguir "não há assinatura" (`none`) de
             * "ainda não se sabe", porque afirmar a negativa sem o dado seria tão falso quanto
             * afirmar a positiva. `certificate` só existe no caso `company_a1`, e nunca carrega
             * `secret_ref` nem impressão digital.
             */
            'signature_status' => $signature['status'],
            'certificate' => $signature['certificate'],
            'can' => [
                'update' => $canUpdate && $isDraftLike,
                'cancel' => ($user?->can('cancel', $this->resource) ?? false) && $this->status->isCancelable(),
                'delete' => ($user?->can('delete', $this->resource) ?? false) && $isDraftLike,
                'resend' => $canUpdate && $inProgress,
                'duplicate' => $canView,
                'move' => $user?->can('move', $this->resource) ?? false,
            ],
        ];
    }

    /**
     * Rótulo de prazo. Ele é o marcador de urgência da tira de metadados (DESIGN §6.4,
     * ROUTES §2.7) e só faz sentido enquanto a coleta corre: num envelope concluído,
     * recusado ou cancelado não há prazo nenhum a correr, e a contagem regressiva
     * ("Expira em 02 out (23 dias)") aparecia ao lado do banner de conclusão.
     */
    protected function expiresLabel(string $timezone): ?string
    {
        if ($this->expires_at === null) {
            return null;
        }

        $expires = $this->expires_at->setTimezone($timezone);
        $date = $expires->translatedFormat('d M');

        if ($this->status === EnvelopeStatus::Expired || $this->expires_at->isPast()) {
            return 'Prazo encerrado em '.$date;
        }

        if ($this->status->isTerminal()) {
            // Concluído, recusado ou cancelado antes do vencimento: o prazo perdeu o objeto.
            return null;
        }

        $days = (int) Carbon::now($timezone)->startOfDay()->diffInDays($expires->copy()->startOfDay());

        return match (true) {
            $days <= 0 => 'Expira hoje',
            $days === 1 => 'Expira amanhã ('.$date.')',
            default => "Expira em {$date} ({$days} dias)",
        };
    }
}
