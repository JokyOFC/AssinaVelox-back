<?php

namespace App\Jobs\Envelopes;

use App\Models\Organization;
use App\Models\Recipient;
use App\Services\Envelopes\Reminders\ReminderSender;
use App\Support\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Um lembrete (destinatário, número) — despachado por `envelopes:send-reminders`.
 *
 * Carrega só ids: nada de token, e-mail ou modelo serializado. Toda a revalidação acontece
 * no ReminderSender, sob lock; este job apenas define a organização corrente (arquitetura
 * §7) e delega. Retentativas são seguras: a chave (destinatário, número) impede duplicar.
 */
class SendReminderToRecipient implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $recipientId,
        public readonly int $sequence,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        return 'reminder:'.$this->recipientId.':'.$this->sequence;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(ReminderSender $sender): void
    {
        $organizationId = Recipient::withoutOrganizationScope()->whereKey($this->recipientId)->value('organization_id');

        /** @var Organization|null $organization */
        $organization = $organizationId !== null
            ? Organization::query()->whereKey($organizationId)->first()
            : null;

        if ($organization === null) {
            return;
        }

        $result = CurrentOrganization::instance()->runAs(
            $organization,
            fn (): array => $sender->send($this->recipientId, $this->sequence),
        );

        if ($result['outcome'] !== ReminderSender::SENT) {
            Log::info('Lembrete automático não enviado.', [
                'recipient_id' => $this->recipientId,
                'sequence' => $this->sequence,
                'outcome' => $result['outcome'],
                'reason' => $result['reason'],
            ]);
        }
    }
}
