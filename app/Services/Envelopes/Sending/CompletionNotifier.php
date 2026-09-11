<?php

namespace App\Services\Envelopes\Sending;

use App\Enums\AccessLinkPurpose;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Notifications\Envelopes\EnvelopeCompletedNotification;
use App\Services\Organizations\NotificationPreferences;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Aviso de conclusão — CONTRATO para o agente da finalização (incremento 4).
 *
 * Chamar `notify($envelope)` **depois** de o envelope estar `completed`, com
 * `final_document_version_id` e `verification_records` já gravados. Aqui não se decide nada
 * sobre o arquivo: apenas emite um link de download autorizado por signatário e despacha
 * as mensagens.
 *
 * O link de download é um `recipient_access_link` com `purpose = download` e prazo próprio
 * (padrão 30 dias) — separado do link de assinatura, que já foi revogado. Sem URL pública,
 * sem anexo do PDF por e-mail.
 *
 * Idempotente por envelope: a marca fica em `settings.completion_notified_at`.
 */
class CompletionNotifier
{
    /** Validade do link de download enviado por e-mail, em dias. */
    public const DOWNLOAD_LINK_DAYS = 30;

    public function __construct(
        private readonly AccessLinks $links,
        private readonly NotificationPreferences $preferences,
    ) {}

    /**
     * @return array{notified: int, sender_notified: bool}
     */
    public function notify(Envelope $envelope, bool $force = false): array
    {
        if ($envelope->status !== EnvelopeStatus::Completed) {
            return ['notified' => 0, 'sender_notified' => false];
        }

        if (! $force && $envelope->setting('completion_notified_at') !== null) {
            return ['notified' => 0, 'sender_notified' => false];
        }

        $settings = $envelope->settings ?? [];
        $settings['completion_notified_at'] = Carbon::now()->toIso8601String();
        $envelope->forceFill(['settings' => $settings])->save();

        $expiresAt = Carbon::now()->addDays(self::DOWNLOAD_LINK_DAYS);
        $notified = 0;

        // Fase 2 §2.4 (B-DOM, alteração mínima): além de quem assinou/aprovou, o
        // visualizador ainda ativo recebe a cópia final.
        $recipients = $envelope->recipients()
            ->where(fn ($query) => $query
                ->where('status', RecipientStatus::Signed->value)
                ->orWhere(fn ($viewer) => $viewer
                    ->where('role', RecipientRole::Viewer->value)
                    ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Notified->value, RecipientStatus::Viewed->value])))
            ->get();

        foreach ($recipients as $recipient) {
            $issued = $this->links->issue($recipient, AccessLinkPurpose::Download, $expiresAt, $envelope);

            Notification::route('mail', $recipient->email)->notify(
                new EnvelopeCompletedNotification($envelope, (string) Str::ulid(), $recipient, $issued->url),
            );

            $notified++;
        }

        return ['notified' => $notified, 'sender_notified' => $this->notifySender($envelope)];
    }

    private function notifySender(Envelope $envelope): bool
    {
        $creator = $envelope->creator;

        if ($creator === null) {
            return false;
        }

        $membership = $creator->memberships()
            ->where('organization_id', $envelope->organization_id)
            ->first();

        $channels = $membership === null
            ? ['mail', 'database']
            : array_values(array_filter([
                $this->preferences->wants($membership, 'envelope_completed', 'mail') ? 'mail' : null,
                $this->preferences->wants($membership, 'envelope_completed', 'database') ? 'database' : null,
            ]));

        if ($channels === []) {
            return false;
        }

        $creator->notify(
            (new EnvelopeCompletedNotification($envelope, (string) Str::ulid()))
                ->restrictChannels($channels),
        );

        return true;
    }
}
