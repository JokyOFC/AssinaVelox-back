<?php

namespace App\Services\Signing\Channels;

use App\Models\Envelope;
use App\Models\Recipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Aviso do convite pelo canal escolhido (SMS/WhatsApp), fora do ciclo da requisição.
 *
 * O link do convite viaja no payload — o mesmo compromisso do e-mail de convite — e por isso
 * o job é cifrado (`ShouldBeEncrypted`): o link não fica legível em `jobs`, no Redis nem em
 * `failed_jobs`.
 */
final class SendChannelInvitation implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $recipientId,
        public readonly string $url,
        public readonly bool $isReminder,
        public readonly string $correlationId,
    ) {
        $this->onQueue((string) config('assinavelox.queues.notifications', 'notifications'));
    }

    public function handle(ChannelInvitations $invitations): void
    {
        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($this->recipientId)->first();

        if ($recipient === null) {
            return;
        }

        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($recipient->envelope_id)->first();

        if ($envelope === null) {
            return;
        }

        $invitations->deliver($recipient, $envelope, $this->url, $this->isReminder, $this->correlationId);
    }
}
