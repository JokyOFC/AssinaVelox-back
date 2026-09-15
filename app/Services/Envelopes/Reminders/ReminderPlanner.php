<?php

namespace App\Services\Envelopes\Reminders;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * SELEÇÃO dos lembretes devidos (`envelopes:send-reminders`, de hora em hora).
 *
 * Um destinatário recebe lembrete quando, ao mesmo tempo:
 *
 *  - a flag `features.reminders` está ligada para a organização (RemindersFeature);
 *  - o envelope está `in_progress`, dentro do prazo, com `settings.reminders.enabled`;
 *  - agora está dentro da janela de horário da organização (ReminderWindow);
 *  - o destinatário está pendente E já foi convidado (`notified`/`viewed`), não é
 *    `viewer`, e é a vez dele (no sequencial só `order_index = current_order`; no paralelo
 *    todos os pendentes);
 *  - ainda não atingiu `max_count` lembretes enviados;
 *  - passou `first_after_days` (primeiro lembrete) ou `interval_days` (demais) desde o
 *    ÚLTIMO contato — `recipients.last_notified_at`, que o convite, o reenvio manual e o
 *    próprio lembrete atualizam. Um reenvio manual, portanto, reinicia a contagem;
 *  - não está com a página de assinatura aberta agora (link usado nos últimos
 *    `assinavelox.reminders.active_grace_minutes`, padrão 60): o lembrete emite link novo e
 *    revoga o anterior, e isso derrubaria quem está assinando.
 *
 * Esta classe só SELECIONA. O envio é de ReminderSender, que revalida TUDO sob lock no
 * momento do envio — o envelope pode ter mudado entre a seleção e o job.
 */
class ReminderPlanner
{
    /** Valor de `recipients.role` que nunca recebe lembrete (roadmap §2.4). */
    public const VIEWER_ROLE = 'viewer';

    public function __construct(
        private readonly RemindersFeature $feature,
        private readonly ReminderLog $log,
    ) {}

    /**
     * @return list<array{envelope_id: int, recipient_id: int, sequence: int}>
     */
    public function due(?CarbonInterface $now = null, ?int $limit = null): array
    {
        $now ??= Carbon::now();
        $limit ??= (int) config('assinavelox.reminders.batch_size', 500);

        if (! RemindersFeature::globallyEnabled() || $limit < 1) {
            return [];
        }

        $due = [];

        Envelope::withoutOrganizationScope()
            ->with('organization')
            ->where('status', EnvelopeStatus::InProgress->value)
            ->whereNotNull('sent_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->where('settings->reminders->enabled', true)
            ->orderBy('id')
            ->chunkById(200, function (Collection $envelopes) use ($now, $limit, &$due): bool {
                foreach ($envelopes as $envelope) {
                    foreach ($this->dueForEnvelope($envelope, $now) as $candidate) {
                        $due[] = $candidate;

                        if (count($due) >= $limit) {
                            return false;
                        }
                    }
                }

                return true;
            });

        return $due;
    }

    /**
     * @return list<array{envelope_id: int, recipient_id: int, sequence: int}>
     */
    public function dueForEnvelope(Envelope $envelope, CarbonInterface $now): array
    {
        $organization = $envelope->organization;

        if (! $this->feature->enabledFor($organization)) {
            return [];
        }

        $settings = ReminderSettings::forEnvelope($envelope);

        if ($settings === null || ! $settings->enabled) {
            return [];
        }

        if (! ReminderWindow::forOrganization($organization)->contains($now)) {
            return [];
        }

        $stats = $this->log->statsForEnvelope((int) $envelope->getKey());
        $candidates = [];

        foreach ($this->eligibleRecipients($envelope) as $recipient) {
            $recipientStats = $stats[(int) $recipient->getKey()] ?? ['total' => 0, 'sent' => 0, 'last_sent_at' => null];

            if (! $this->isDue($recipient, $settings, $recipientStats['sent'], $now)) {
                continue;
            }

            if ($this->recentlyActive($recipient, $now)) {
                continue;
            }

            $candidates[] = [
                'envelope_id' => (int) $envelope->getKey(),
                'recipient_id' => (int) $recipient->getKey(),
                'sequence' => $recipientStats['total'] + 1,
            ];
        }

        return $candidates;
    }

    /**
     * Pendentes da vez, já convidados, que não são visualizadores.
     *
     * @return Collection<int, Recipient>
     */
    public function eligibleRecipients(Envelope $envelope): Collection
    {
        $query = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('status', [RecipientStatus::Notified->value, RecipientStatus::Viewed->value])
            ->where('role', '!=', self::VIEWER_ROLE)
            ->orderBy('order_index')
            ->orderBy('id');

        // Fase 3 §3.3 (F-FLOW): com etapas, só a vez (etapa) corrente; sem etapas = sequencial.
        if ($envelope->hasTurns()) {
            $query->where('order_index', (int) $envelope->current_order);
        }

        return $query->get();
    }

    public function isDue(Recipient $recipient, ReminderSettings $settings, int $alreadySent, CarbonInterface $now): bool
    {
        if ($alreadySent >= $settings->maxCount) {
            return false;
        }

        $lastContact = $recipient->last_notified_at;

        if ($lastContact === null) {
            return false;
        }

        return $lastContact->copy()->addDays($settings->delayDaysFor($alreadySent))->lessThanOrEqualTo($now);
    }

    /**
     * O destinatário abriu o link há pouco? Então provavelmente está assinando agora.
     */
    public function recentlyActive(Recipient $recipient, CarbonInterface $now): bool
    {
        $grace = (int) config('assinavelox.reminders.active_grace_minutes', 60);

        if ($grace <= 0) {
            return false;
        }

        return RecipientAccessLink::withoutOrganizationScope()
            ->where('recipient_id', $recipient->getKey())
            ->whereNull('revoked_at')
            ->where('last_used_at', '>', $now->copy()->subMinutes($grace))
            ->exists();
    }
}
