<?php

namespace App\Services\Envelopes\Reminders;

use App\Enums\AccessLinkPurpose;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Notifications\Envelopes\RecipientReminderNotification;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Envelopes\Sending\ResendInvitations;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * ENVIO de um lembrete (destinatário, número do lembrete) — chamado pelo job
 * App\Jobs\Envelopes\SendReminderToRecipient.
 *
 * Tudo é revalidado AGORA, numa transação com `SELECT ... FOR UPDATE` no envelope e no
 * destinatário: a seleção (ReminderPlanner) pode ter acontecido minutos antes, e nesse meio
 * tempo o envelope pode ter sido concluído, recusado, expirado ou cancelado, ou o
 * destinatário pode ter assinado. Lembrete nunca sai em estado terminal.
 *
 * Resultados:
 *
 *  - `sent`: link novo emitido (o anterior revogado), `last_notified_at` atualizado,
 *    linha `sent` em `envelope_reminders`, `reminder.sent` na trilha e e-mail enfileirado
 *    (`delivery_attempts.purpose = reminder`) — tudo na mesma transação;
 *  - `skipped`: o envelope/destinatário deixou de aceitar lembrete. Grava linha `skipped`
 *    e `reminder.skipped` UMA vez (a chave única impede repetir);
 *  - `noop`: nada a fazer agora e nada gravado (já processado, fora da janela, ainda não
 *    venceu, destinatário ativo na página, flag desligada, número desatualizado).
 *
 * Idempotência: UNIQUE(recipient_id, sequence). Duas execuções do comando, ou duas
 * retentativas do mesmo job, produzem no máximo um lembrete com aquele número.
 *
 * Link: o token só existe como digest (`recipient_access_links.token_digest`), então não
 * há como "reusar" no e-mail o link do convite anterior — o lembrete sempre emite um link
 * novo por AccessLinks e revoga o anterior, exatamente como o reenvio manual (Q11). O
 * e-mail mais recente é o único que funciona. Ver docs/fase-2/lembretes-e-agendamento.md §4.
 */
class ReminderSender
{
    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    public const NOOP = 'noop';

    public function __construct(
        private readonly RemindersFeature $feature,
        private readonly ReminderPlanner $planner,
        private readonly ReminderLog $log,
        private readonly AccessLinks $links,
    ) {}

    /**
     * @return array{outcome: string, reason: string|null}
     */
    public function send(int $recipientId, int $sequence, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();

        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($recipientId)->first();

        if ($recipient === null) {
            return $this->noop('recipient_missing');
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($recipient->organization_id)->first();

        if ($organization === null || ! $this->feature->enabledFor($organization)) {
            return $this->noop('feature_disabled');
        }

        /** @var array{outcome: string, reason: string|null} */
        return DB::transaction(function () use ($recipient, $organization, $sequence, $now): array {
            /** @var Envelope|null $envelope */
            $envelope = Envelope::withoutOrganizationScope()
                ->whereKey($recipient->envelope_id)
                ->lockForUpdate()
                ->first();

            if ($envelope === null) {
                return $this->noop('envelope_missing');
            }

            if ($this->log->exists((int) $recipient->getKey(), $sequence)) {
                return $this->noop('already_processed');
            }

            /** @var Recipient|null $fresh */
            $fresh = Recipient::withoutOrganizationScope()
                ->whereKey($recipient->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return $this->noop('recipient_missing');
            }

            $stats = $this->log->statsForRecipient((int) $fresh->getKey());

            // Job atrasado de uma seleção antiga: outro lembrete já ocupou a numeração.
            if ($sequence !== $stats['total'] + 1) {
                return $this->noop('sequence_mismatch');
            }

            $settings = ReminderSettings::forEnvelope($envelope);
            $resends = app(ResendInvitations::class);

            $reason = $this->stopReason($envelope, $fresh, $now) ?? match (true) {
                $settings === null || ! $settings->enabled => 'reminders_disabled',
                $stats['sent'] >= $settings->maxCount => 'max_reached',
                // roadmap §2.5 / Q11: lembretes e reenvios manuais somam contra
                // `organizations.settings.max_resends` (o limite anti-spam da organização).
                $resends->resendCount($fresh) >= $resends->maxResends($organization) => 'max_resends_reached',
                default => null,
            };

            if ($reason !== null || $settings === null) {
                return $this->skip($envelope, $fresh, $sequence, $reason ?? 'reminders_disabled');
            }

            if (! $this->planner->isDue($fresh, $settings, $stats['sent'], $now)) {
                return $this->noop('not_due');
            }

            if (! ReminderWindow::forOrganization($organization)->contains($now)) {
                return $this->noop('outside_window');
            }

            if ($this->planner->recentlyActive($fresh, $now)) {
                return $this->noop('recently_active');
            }

            $this->deliver($envelope, $fresh, $sequence, $settings);

            return ['outcome' => self::SENT, 'reason' => null];
        }, 3);
    }

    /**
     * Motivo DEFINITIVO para não lembrar (ou null se o destinatário ainda deve ser lembrado).
     */
    public function stopReason(Envelope $envelope, Recipient $recipient, CarbonInterface $now): ?string
    {
        if ($envelope->status !== EnvelopeStatus::InProgress) {
            return 'envelope_'.$envelope->status->value;
        }

        if ($envelope->expires_at !== null && $envelope->expires_at->lessThanOrEqualTo($now)) {
            return 'envelope_expired';
        }

        if (! in_array($recipient->status, [RecipientStatus::Notified, RecipientStatus::Viewed], true)) {
            return 'recipient_'.$recipient->status->value;
        }

        if ($this->roleOf($recipient) === ReminderPlanner::VIEWER_ROLE) {
            return 'viewer';
        }

        if ($envelope->signing_order === SigningOrder::Sequential
            && (int) $recipient->order_index !== (int) $envelope->current_order) {
            return 'not_their_turn';
        }

        return null;
    }

    private function deliver(Envelope $envelope, Recipient $recipient, int $sequence, ReminderSettings $settings): void
    {
        $correlationId = (string) Str::ulid();
        $now = Carbon::now();

        $issued = $this->links->issue($recipient, AccessLinkPurpose::Signing, null, $envelope);

        // `notification_count` NÃO muda: ele mede convite + reenvios MANUAIS. O lembrete tem
        // contagem própria (`envelope_reminders`) e limite próprio (`max_count`), e as duas
        // contagens SOMAM contra `max_resends` (Q11 — ResendInvitations::resendCount).
        $recipient->forceFill(['last_notified_at' => $now])->save();

        // Mesma trava de 10 minutos do reenvio manual: um "Reenviar" logo depois do
        // lembrete não manda um terceiro e-mail em sequência.
        RateLimiter::hit(
            ResendInvitations::throttleKeyFor($recipient),
            (int) config('assinavelox.resend.throttle_minutes', 10) * 60,
        );

        $this->log->record([
            'organization_id' => (int) $envelope->organization_id,
            'envelope_id' => (int) $envelope->getKey(),
            'recipient_id' => (int) $recipient->getKey(),
            'sequence' => $sequence,
            'status' => ReminderLog::SENT,
            'correlation_id' => $correlationId,
            'access_link_id' => (int) $issued->link->getKey(),
            'sent_at' => $now,
        ]);

        EnvelopeAudit::record($envelope, AuditEventType::ReminderSent, [
            // Sem token e sem URL (mesma regra de invitation.sent).
            'recipient' => $recipient->ulid,
            'to' => Recipient::maskEmail($recipient->email),
            'link' => $issued->link->ulid,
            'sequence' => $sequence,
            'max_count' => $settings->maxCount,
        ], $recipient, $correlationId);

        // Enfileirada dentro da transação e marcada `afterCommit`: com fila `database` o job
        // entra na mesma transação; com Redis, só depois do commit. Se a transação cair, nem
        // o link, nem a linha, nem o e-mail existem.
        Notification::route('mail', $recipient->email)->notify(
            new RecipientReminderNotification($recipient, $envelope, $issued->url, $correlationId, $sequence, $settings->maxCount),
        );
    }

    /**
     * @return array{outcome: string, reason: string|null}
     */
    private function skip(Envelope $envelope, Recipient $recipient, int $sequence, string $reason): array
    {
        $recorded = $this->log->record([
            'organization_id' => (int) $envelope->organization_id,
            'envelope_id' => (int) $envelope->getKey(),
            'recipient_id' => (int) $recipient->getKey(),
            'sequence' => $sequence,
            'status' => ReminderLog::SKIPPED,
            'reason' => Str::limit($reason, 40, ''),
        ]);

        if ($recorded) {
            EnvelopeAudit::record($envelope, AuditEventType::ReminderSkipped, [
                'recipient' => $recipient->ulid,
                'sequence' => $sequence,
                'reason' => $reason,
            ], $recipient);
        }

        return ['outcome' => self::SKIPPED, 'reason' => $reason];
    }

    /**
     * @return array{outcome: string, reason: string|null}
     */
    private function noop(string $reason): array
    {
        return ['outcome' => self::NOOP, 'reason' => $reason];
    }

    /**
     * Valor bruto de `recipients.role` — lido sem o cast para funcionar mesmo antes de o
     * enum `RecipientRole` ganhar os papéis da Fase 2 (§2.4).
     */
    private function roleOf(Recipient $recipient): string
    {
        return (string) ($recipient->getRawOriginal('role') ?? '');
    }
}
