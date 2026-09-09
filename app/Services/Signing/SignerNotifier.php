<?php

namespace App\Services\Signing;

use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Signing\Contracts\SignerNotifications;
use Illuminate\Support\Facades\Log;

/**
 * Fachada sobre {@see SignerNotifications}: entrega o aviso quando há implementação
 * registrada e, quando não há, deixa um rastro no log em vez de falhar.
 *
 * A escolha é deliberada. Um aceite gravado com sucesso não pode ser desfeito porque o
 * e-mail do próximo participante não saiu — a manifestação de vontade já aconteceu e está
 * auditada. O que não pode acontecer é o silêncio: o aviso vai para o log com os ids
 * necessários para reprocessar, e nunca com e-mail completo, token ou código.
 */
final class SignerNotifier
{
    private function delegate(): ?SignerNotifications
    {
        if (! app()->bound(SignerNotifications::class)) {
            return null;
        }

        /** @var SignerNotifications */
        return app(SignerNotifications::class);
    }

    /**
     * @param  list<Recipient>  $recipients
     */
    public function inviteRecipients(Envelope $envelope, array $recipients): void
    {
        if ($recipients === []) {
            return;
        }

        $delegate = $this->delegate();

        if ($delegate === null) {
            Log::warning('Chegou a vez de destinatários, mas nenhuma implementação de SignerNotifications está registrada.', [
                'envelope_id' => $envelope->getKey(),
                'organization_id' => $envelope->organization_id,
                'recipient_ids' => array_map(fn (Recipient $r): int => (int) $r->getKey(), $recipients),
            ]);

            return;
        }

        $delegate->inviteRecipients($envelope, $recipients);
    }

    public function notifySenderSigned(Envelope $envelope, Recipient $signedBy): void
    {
        $delegate = $this->delegate();

        if ($delegate === null) {
            Log::warning('Aceite registrado sem aviso ao remetente: SignerNotifications não registrado.', [
                'envelope_id' => $envelope->getKey(),
                'recipient_id' => $signedBy->getKey(),
            ]);

            return;
        }

        $delegate->notifySenderSigned($envelope, $signedBy);
    }

    public function notifySenderRefused(Envelope $envelope, Recipient $refusedBy): void
    {
        $delegate = $this->delegate();

        if ($delegate === null) {
            Log::warning('Recusa registrada sem notificação ao remetente: SignerNotifications não registrado.', [
                'envelope_id' => $envelope->getKey(),
                'recipient_id' => $refusedBy->getKey(),
            ]);

            return;
        }

        $delegate->notifySenderRefused($envelope, $refusedBy);
    }

    /**
     * @param  list<Recipient>  $canceled
     */
    public function notifyEnvelopeClosed(Envelope $envelope, array $canceled, string $reason): void
    {
        $delegate = $this->delegate();

        if ($delegate === null) {
            if ($canceled !== []) {
                Log::warning('Envelope encerrado sem aviso aos pendentes: SignerNotifications não registrado.', [
                    'envelope_id' => $envelope->getKey(),
                    'reason' => $reason,
                    'recipient_ids' => array_map(fn (Recipient $r): int => (int) $r->getKey(), $canceled),
                ]);
            }

            return;
        }

        $delegate->notifyEnvelopeClosed($envelope, $canceled, $reason);
    }
}
