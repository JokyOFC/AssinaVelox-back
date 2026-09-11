<?php

namespace App\Services\Envelopes\Sending;

use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Envelopes\EnvelopeCanceledNotification;
use App\Notifications\Envelopes\EnvelopeRefusedNotification;
use App\Notifications\Envelopes\RecipientSignedNotification;
use App\Services\Organizations\NotificationPreferences;
use App\Services\Signing\Contracts\SignerNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Implementação de `App\Services\Signing\Contracts\SignerNotifications` — a ponte entre o
 * fluxo público do signatário e a camada de envio.
 *
 * Divisão combinada entre os módulos: quem **muda estado** é `App\Services\Signing`
 * (`RecordAcceptance` / `RecordRefusal`) — avançar a ordem, marcar `refused`/`canceled`,
 * revogar links e sessões, gravar a trilha, tudo sob lock. Quem **produz mensagem** é esta
 * classe:
 *
 *  - chegou a vez → convite do próximo (link novo, `invitation.sent`, `delivery_attempts`);
 *  - alguém recusou → aviso ao remetente, com o motivo;
 *  - envelope encerrado → aviso a quem tinha sido convidado e não assinou.
 *
 * Um único escritor de estado por transição; um único lugar que sabe emitir link e escrever
 * e-mail. Registrada no `AppServiceProvider`; sem o binding, o fluxo público apenas registra
 * no log que ninguém foi avisado — e o aceite continua válido.
 */
class EnvelopeNotifications implements SignerNotifications
{
    public function __construct(
        private readonly InvitationDispatcher $invitations,
        private readonly NotificationPreferences $preferences,
    ) {}

    /**
     * @param  list<Recipient>  $recipients
     */
    public function inviteRecipients(Envelope $envelope, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            $this->invitations->dispatch($recipient, $envelope, isReminder: false);
        }
    }

    /**
     * "Fulano assinou" — o aviso que o remetente mais espera do fluxo (ROUTES §2.14,
     * `recipient_signed`, ligado por padrão nos dois canais). O interruptor existia na tela
     * de Notificações desde o começo e nada o produzia.
     */
    public function notifySenderSigned(Envelope $envelope, Recipient $signedBy): void
    {
        $creator = $envelope->creator;
        $channels = $this->channelsFor($envelope, 'recipient_signed');

        if ($creator === null || $channels === []) {
            return;
        }

        // "2 de 3 assinaram": o denominador é quem tem aceite a dar. O visualizador
        // (Fase 2 §2.4) nunca assina — contá-lo deixaria o contador sempre incompleto.
        $total = $envelope->recipients()->participating()->count();
        $signed = $envelope->recipients()->participating()->where('status', RecipientStatus::Signed->value)->count();

        $creator->notify(
            (new RecipientSignedNotification($envelope, $signedBy, (string) Str::ulid(), $signed, $total))
                ->restrictChannels($channels),
        );
    }

    public function notifySenderRefused(Envelope $envelope, Recipient $refusedBy): void
    {
        $creator = $envelope->creator;
        $channels = $this->channelsFor($envelope, 'recipient_refused');

        if ($creator === null || $channels === []) {
            return;
        }

        $creator->notify(
            (new EnvelopeRefusedNotification($envelope, $refusedBy, (string) Str::ulid(), $refusedBy->refusal_reason))
                ->restrictChannels($channels),
        );
    }

    /**
     * @param  list<Recipient>  $canceled
     */
    public function notifyEnvelopeClosed(Envelope $envelope, array $canceled, string $reason): void
    {
        $refused = $reason === 'recipient_refused';

        $motive = $refused
            ? $envelope->setting('refusal_reason')
            : $envelope->setting('cancel_reason');

        foreach ($canceled as $recipient) {
            // Quem nunca chegou a ser convidado (aguardava a vez no sequencial) não recebe
            // aviso de encerramento: contar do fim de um documento que ele não sabia que
            // existia não ajuda ninguém e expõe informação sem necessidade.
            if ((int) $recipient->notification_count < 1) {
                continue;
            }

            Notification::route('mail', $recipient->email)->notify(
                new EnvelopeCanceledNotification(
                    $recipient,
                    $envelope,
                    (string) Str::ulid(),
                    is_string($motive) ? $motive : null,
                    refusedByAnother: $refused,
                ),
            );
        }
    }

    /**
     * Canais permitidos pelas preferências de quem criou o envelope
     * (`memberships.notification_preferences`, ROUTES §2.14). Sem membership — usuário
     * removido da organização — vale o padrão do catálogo.
     *
     * @return list<string>
     */
    private function channelsFor(Envelope $envelope, string $event): array
    {
        $creator = $envelope->creator;

        if ($creator === null) {
            return [];
        }

        $membership = $creator->memberships()
            ->where('organization_id', $envelope->organization_id)
            ->first();

        if ($membership === null) {
            return ['mail', 'database'];
        }

        return array_values(array_filter([
            $this->preferences->wants($membership, $event, 'mail') ? 'mail' : null,
            $this->preferences->wants($membership, $event, 'database') ? 'database' : null,
        ]));
    }
}
