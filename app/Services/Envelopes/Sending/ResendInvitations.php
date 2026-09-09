<?php

namespace App\Services\Envelopes\Sending;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Reenvio MANUAL de convites (RECONCILIACAO Q11) — não é lembrete automático (Fase 2).
 *
 * Três limites, todos verificáveis:
 *  1. **10 minutos por destinatário** (`assinavelox.resend.throttle_minutes`), com
 *     `RateLimiter` — a mesma trava vale para o botão da linha, o "Lembrar pendentes" e o
 *     lote da tela Assinaturas;
 *  2. **máximo de reenvios por destinatário** (`organizations.settings.max_resends`,
 *     padrão 5), contado por `recipients.notification_count` menos o convite inicial;
 *  3. **1 hora por organização** para o "Lembrar todos os pendentes" da tela Assinaturas
 *     (`assinavelox.resend.bulk_throttle_minutes`).
 *
 * Cada reenvio emite um link NOVO e revoga o anterior: o e-mail mais recente é o único que
 * funciona.
 */
class ResendInvitations
{
    public function __construct(private readonly InvitationDispatcher $invitations) {}

    /**
     * Reenvia para um destinatário. Lança `SendingException` com mensagem PT-BR quando não
     * é permitido.
     *
     * @throws SendingException
     */
    public function one(Envelope $envelope, Recipient $recipient): void
    {
        $this->assertEnvelopeAcceptsResend($envelope);

        if (! $recipient->status->isPendingSignature()) {
            throw SendingException::recipientNotPending();
        }

        if (! $this->invitations->isTheirTurn($recipient, $envelope)) {
            throw SendingException::notTheirTurn();
        }

        $max = $this->maxResends($envelope->organization);

        if ($this->resendCount($recipient) >= $max) {
            throw SendingException::resendLimitReached($max);
        }

        $seconds = RateLimiter::availableIn($this->key($recipient));

        if ($seconds > 0) {
            throw SendingException::resendThrottled((int) ceil($seconds / 60));
        }

        RateLimiter::hit($this->key($recipient), $this->throttleMinutes() * 60);

        $this->invitations->resend($recipient, $envelope);
    }

    /**
     * Reenvia para todos os pendentes elegíveis do envelope. Não lança por destinatário
     * bloqueado — devolve a contagem para a mensagem de flash.
     *
     * @return array{sent: int, skipped: int}
     *
     * @throws SendingException quando o envelope inteiro não aceita reenvio
     */
    public function all(Envelope $envelope): array
    {
        $this->assertEnvelopeAcceptsResend($envelope);

        $recipients = $this->eligible($envelope);

        if ($recipients->isEmpty()) {
            throw SendingException::nothingToResend();
        }

        $sent = 0;
        $skipped = 0;

        foreach ($recipients as $recipient) {
            try {
                $this->one($envelope, $recipient);
                $sent++;
            } catch (SendingException) {
                $skipped++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * Destinatários que podem receber reenvio agora (pendentes e na vez).
     *
     * @return Collection<int, Recipient>
     */
    public function eligible(Envelope $envelope): Collection
    {
        $query = $envelope->recipients()->whereIn('status', [
            RecipientStatus::Pending->value,
            RecipientStatus::Notified->value,
            RecipientStatus::Viewed->value,
        ]);

        if ($envelope->isSequential()) {
            $query->where('order_index', (int) $envelope->current_order);
        }

        return $query->get();
    }

    /**
     * Trava global da organização para o "Lembrar todos os pendentes" da tela Assinaturas.
     * Devolve os minutos restantes (0 = liberado).
     */
    public function organizationCooldownMinutes(Organization $organization): int
    {
        $seconds = RateLimiter::availableIn($this->organizationKey($organization));

        return $seconds > 0 ? (int) ceil($seconds / 60) : 0;
    }

    public function markOrganizationCooldown(Organization $organization): void
    {
        RateLimiter::hit(
            $this->organizationKey($organization),
            (int) config('assinavelox.resend.bulk_throttle_minutes', 60) * 60,
        );
    }

    /**
     * Quantos reenvios este destinatário já recebeu (o convite inicial não conta).
     */
    public function resendCount(Recipient $recipient): int
    {
        return max(0, (int) $recipient->notification_count - 1);
    }

    /**
     * Minutos que faltam para o próximo reenvio deste destinatário (0 = pode agora).
     */
    public function cooldownMinutes(Recipient $recipient): int
    {
        $seconds = RateLimiter::availableIn($this->key($recipient));

        return $seconds > 0 ? (int) ceil($seconds / 60) : 0;
    }

    /**
     * O botão "Reenviar" deve estar habilitado? (ROUTES §2.7 `can_resend`.)
     */
    public function canResend(Envelope $envelope, Recipient $recipient): bool
    {
        return $envelope->status === EnvelopeStatus::InProgress
            && $recipient->status->isPendingSignature()
            && $this->invitations->isTheirTurn($recipient, $envelope)
            && $this->resendCount($recipient) < $this->maxResends($envelope->organization)
            && $this->cooldownMinutes($recipient) === 0;
    }

    public function maxResends(Organization $organization): int
    {
        return (int) $organization->setting(
            'max_resends',
            config('assinavelox.resend.max_per_recipient', 5),
        );
    }

    private function throttleMinutes(): int
    {
        return (int) config('assinavelox.resend.throttle_minutes', 10);
    }

    /**
     * @throws SendingException
     */
    private function assertEnvelopeAcceptsResend(Envelope $envelope): void
    {
        if ($envelope->status !== EnvelopeStatus::InProgress) {
            throw SendingException::invalidStatus();
        }
    }

    private function key(Recipient $recipient): string
    {
        return 'invitation-resend:'.$recipient->getKey();
    }

    private function organizationKey(Organization $organization): string
    {
        return 'invitation-resend-bulk:'.$organization->getKey();
    }
}
