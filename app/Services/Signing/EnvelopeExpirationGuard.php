<?php

namespace App\Services\Signing;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Signing\Contracts\RevalidatesEnvelopeExpiration;
use Illuminate\Support\Facades\DB;

/**
 * Guarda padrão da revalidação de prazo, usado quando o módulo de envio não registrou a sua
 * implementação de {@see RevalidatesEnvelopeExpiration}.
 *
 * Existe para que a invariante "o prazo é revalidado a cada acesso" (RECONCILIACAO Q22) não
 * dependa da ordem em que os módulos foram escritos nem de um binding presente. Roda em
 * transação curta com `lockForUpdate` para que dois signatários abrindo o link no mesmo
 * segundo não gravem dois `envelope.expired`, e não lança: envelope terminal, sem prazo ou
 * dentro do prazo volta como está.
 */
final class EnvelopeExpirationGuard implements RevalidatesEnvelopeExpiration
{
    public function revalidate(Envelope $envelope): Envelope
    {
        if (! $this->isDue($envelope)) {
            return $envelope;
        }

        return DB::transaction(function () use ($envelope): Envelope {
            /** @var Envelope|null $locked */
            $locked = Envelope::withoutOrganizationScope()
                ->whereKey($envelope->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return $envelope;
            }

            // Outra requisição pode ter expirado (ou concluído) entre a leitura e o lock.
            if (! $this->isDue($locked)) {
                return $locked;
            }

            $locked->transitionTo(EnvelopeStatus::Expired);
            $locked->save();

            $expired = 0;

            Recipient::withoutOrganizationScope()
                ->where('envelope_id', $locked->getKey())
                ->whereIn('status', [
                    RecipientStatus::Pending->value,
                    RecipientStatus::Notified->value,
                    RecipientStatus::Viewed->value,
                ])
                ->get()
                ->each(function (Recipient $recipient) use (&$expired): void {
                    $recipient->transitionTo(RecipientStatus::Expired);
                    $recipient->save();
                    $expired++;
                });

            SignerAudit::system($locked, AuditEventType::EnvelopeExpired, [
                'reason' => 'deadline_reached_on_access',
                'expires_at' => $locked->expires_at?->toIso8601String(),
                'recipients_expired' => $expired,
            ]);

            return $locked;
        });
    }

    private function isDue(Envelope $envelope): bool
    {
        return $envelope->status === EnvelopeStatus::InProgress
            && $envelope->expires_at !== null
            && $envelope->expires_at->isPast();
    }
}
