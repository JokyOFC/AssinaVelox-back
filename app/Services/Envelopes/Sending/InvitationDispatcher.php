<?php

namespace App\Services\Envelopes\Sending;

use App\Enums\AccessLinkPurpose;
use App\Enums\AuditEventType;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Services\Envelopes\Contracts\RotatesInvitations;
use App\Services\Envelopes\EnvelopeAudit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Emissão e despacho de convites (ROUTES §3.1, RECONCILIACAO Q11).
 *
 * Um convite = (1) um `recipient_access_link` novo, que revoga o anterior; (2) o
 * destinatário passa de `pending` a `notified`; (3) `invitation.sent`/`invitation.resent`
 * na trilha, SEM o token; (4) a notificação enfileirada.
 *
 * Quem recebe convite depende da ordem de assinatura (arquitetura §3.2):
 *  - `parallel`: todos os pendentes de uma vez;
 *  - `sequential`: apenas quem está em `envelopes.current_order` — os demais ficam
 *    `pending` ("Aguarda a vez") até o aceite anterior avançar a ordem.
 *
 * Também implementa `RotatesInvitations`, o ponto de extensão que o agente de
 * destinatários (B-FIELDS) chama quando o e-mail de um pendente muda: o link antigo já foi
 * revogado por ele; aqui sai o novo.
 */
class InvitationDispatcher implements RotatesInvitations
{
    public function __construct(private readonly AccessLinks $links) {}

    /**
     * Convites do envio inicial: ordem 1 no sequencial, todos no paralelo.
     *
     * @return int quantidade de convites despachados
     */
    public function dispatchInitial(Envelope $envelope): int
    {
        return $this->dispatchMany($this->pendingForCurrentTurn($envelope), $envelope);
    }

    /**
     * Convites de quem passou a ser a vez (chamado pelo agente do aceite depois de avançar
     * `current_order`). Idempotente: quem já está `notified` não é notificado de novo.
     *
     * @return int quantidade de convites despachados
     */
    public function notifyCurrentTurn(Envelope $envelope): int
    {
        return $this->dispatchMany($this->pendingForCurrentTurn($envelope), $envelope);
    }

    /**
     * Reenvio manual de um convite (novo link, revoga o anterior).
     */
    public function resend(Recipient $recipient, Envelope $envelope, ?string $correlationId = null): void
    {
        $this->dispatch($recipient, $envelope, true, $correlationId);
    }

    /**
     * Ponto de extensão de `RecipientSync`: o e-mail do pendente mudou, emita novo link.
     *
     * Devolve `false` sem fazer nada quando não há convite a emitir — e a interface, então,
     * pede o reenvio manual em vez de afirmar que o convite saiu.
     */
    public function rotate(Recipient $recipient, string $reason): bool
    {
        $envelope = $recipient->relationLoaded('envelope')
            ? $recipient->envelope
            : Envelope::withoutOrganizationScope()->whereKey($recipient->envelope_id)->first();

        // Envelope ainda em preparo: não existe versão congelada nem link a emitir.
        if ($envelope === null || $envelope->sent_document_version_id === null || $envelope->status->isTerminal()) {
            return false;
        }

        // No sequencial, quem ainda aguarda a vez não tem convite para rotacionar.
        if (! $this->isTheirTurn($recipient, $envelope) || ! $recipient->status->isPendingSignature()) {
            return false;
        }

        $this->dispatch($recipient, $envelope, true, null, ['reason' => $reason]);

        return true;
    }

    /**
     * Emite o link e despacha a notificação de um destinatário.
     *
     * O `correlationId` liga a linha da trilha à tentativa em `delivery_attempts` e é a
     * chave de idempotência da entrega: uma retentativa do MESMO job reaproveita a linha e
     * não reenvia o que já saiu. Cada mensagem tem o seu (a coluna é CHAR(26), um ULID).
     *
     * @param  array<string, mixed>  $auditExtra
     */
    public function dispatch(
        Recipient $recipient,
        Envelope $envelope,
        bool $isReminder,
        ?string $correlationId = null,
        array $auditExtra = [],
    ): void {
        $correlationId ??= (string) Str::ulid();

        $issued = $this->links->issue($recipient, AccessLinkPurpose::Signing, null, $envelope);

        // `notified` só a partir de `pending`: quem já visualizou não regride de estado.
        if ($recipient->status === RecipientStatus::Pending) {
            $recipient->transitionTo(RecipientStatus::Notified);
        }

        $recipient->forceFill([
            'status' => $recipient->status,
            'notification_count' => (int) $recipient->notification_count + 1,
            'last_notified_at' => Carbon::now(),
        ])->save();

        EnvelopeAudit::record(
            $envelope,
            $isReminder ? AuditEventType::InvitationResent : AuditEventType::InvitationSent,
            array_merge([
                // Sem token e sem URL: só o identificador opaco do link e o e-mail mascarado.
                'link' => $issued->link->ulid,
                'recipient' => $recipient->ulid,
                'to' => Recipient::maskEmail($recipient->email),
                'notification_count' => (int) $recipient->notification_count,
            ], $auditExtra),
            $recipient,
            $correlationId,
        );

        Notification::route('mail', $recipient->email)->notify(
            new RecipientInvitationNotification($recipient, $envelope, $issued->url, $correlationId, $isReminder),
        );
    }

    /**
     * Destinatários que devem receber convite agora.
     *
     * @return Collection<int, Recipient>
     */
    public function pendingForCurrentTurn(Envelope $envelope): Collection
    {
        $query = $envelope->recipients()->where('status', RecipientStatus::Pending->value);

        if ($envelope->signing_order === SigningOrder::Sequential) {
            $query->where('order_index', (int) $envelope->current_order);
        }

        return $query->get();
    }

    public function isTheirTurn(Recipient $recipient, Envelope $envelope): bool
    {
        return $envelope->signing_order !== SigningOrder::Sequential
            || (int) $recipient->order_index === (int) $envelope->current_order;
    }

    /**
     * @param  Collection<int, Recipient>  $recipients
     */
    private function dispatchMany(Collection $recipients, Envelope $envelope, bool $isReminder = false): int
    {
        $count = 0;

        foreach ($recipients as $recipient) {
            // Um correlation_id por MENSAGEM: a coluna é CHAR(26) e a idempotência da
            // entrega é por tentativa, não por lote.
            $this->dispatch($recipient, $envelope, $isReminder);
            $count++;
        }

        return $count;
    }
}
