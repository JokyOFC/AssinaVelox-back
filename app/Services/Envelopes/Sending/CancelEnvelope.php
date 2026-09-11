<?php

namespace App\Services\Envelopes\Sending;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\PlanConsumptionStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Plans\PlanLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cancelamento pelo remetente (arquitetura §3.2: `draft|preparing|ready|in_progress →
 * canceled`).
 *
 * O que acontece, nesta ordem:
 *  1. sob lock: transição do envelope, pendentes → `canceled`, links revogados, trilha;
 *  2. fora da transação: aviso por e-mail a quem tinha sido convidado e ainda não assinou;
 *  3. o consumo do plano é **liberado somente se o envelope não avançou** — ninguém
 *     assinou. Se já houve pelo menos um aceite, o envio produziu efeito e a cota fica
 *     consumida.
 *
 * Idempotente: chamar duas vezes não notifica duas vezes nem libera o consumo duas vezes.
 */
class CancelEnvelope
{
    public function __construct(
        private readonly AccessLinks $links,
        private readonly PlanLedger $ledger,
        private readonly EnvelopeNotifications $notifications,
    ) {}

    /**
     * @return array{canceled: bool, notified: int, consumption_released: bool}
     */
    public function handle(Envelope $envelope, ?string $reason = null): array
    {
        $correlationId = (string) Str::ulid();

        /** @var array{canceled: bool, notify: list<int>, signed: int} $result */
        $result = DB::transaction(function () use ($envelope, $reason, $correlationId): array {
            /** @var Envelope|null $locked */
            $locked = Envelope::withoutOrganizationScope()
                ->whereKey($envelope->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->status->isCancelable()) {
                return ['canceled' => false, 'notify' => [], 'signed' => 0];
            }

            $pending = $locked->recipients()
                ->whereIn('status', [
                    RecipientStatus::Pending->value,
                    RecipientStatus::Notified->value,
                    RecipientStatus::Viewed->value,
                ])
                ->get();

            // Quem já assinou é contado (para decidir sobre a cota) e identificado (para
            // manter o link): o acesso dele à própria prova não pode depender de o
            // remetente cancelar ou não.
            $signedIds = array_values(array_map(
                static fn ($id): int => (int) $id,
                $locked->recipients()
                    ->where('status', RecipientStatus::Signed->value)
                    ->pluck('id')
                    ->all(),
            ));

            $signed = count($signedIds);

            // Quem AINDA aguardava a vez no sequencial nunca recebeu convite: avisá-lo do
            // cancelamento seria contar de um documento que ele nunca soube que existia.
            // A lista é calculada antes da transição, que sobrescreve o status.
            $notify = $pending
                ->filter(fn ($recipient) => $recipient->status !== RecipientStatus::Pending)
                ->modelKeys();

            $locked->transitionTo(EnvelopeStatus::Canceled);
            $settings = $locked->settings ?? [];
            $settings['cancel_reason'] = $reason;
            $locked->settings = $settings;
            $locked->save();

            // Fase 2 §2.5: um envelope cancelado não pode ser enviado na hora agendada.
            ScheduledSend::clearWithinLock($locked, 'envelope_canceled');

            foreach ($pending as $recipient) {
                $recipient->transitionTo(RecipientStatus::Canceled);
                $recipient->save();
            }

            // Quem já assinou mantém o link: é por ele que a pessoa chega ao próprio
            // comprovante de aceite e ao documento que assinou. Cancelar encerra o pedido;
            // não apaga, para quem já se manifestou, a prova do que fez. Mesma regra da
            // expiração (ExpireEnvelopes) — e manter o link não reabre nada, porque o
            // resolver recusa assinar fora de `in_progress`.
            $this->links->revokeForEnvelope($locked, exceptRecipientIds: $signedIds);

            EnvelopeAudit::record($locked, AuditEventType::EnvelopeCanceled, [
                'has_reason' => filled($reason),
                'pending_recipients' => $pending->count(),
                'signed_recipients' => $signed,
            ], null, $correlationId);

            return [
                'canceled' => true,
                'notify' => $notify,
                'signed' => $signed,
            ];
        }, 3);

        if (! $result['canceled']) {
            return ['canceled' => false, 'notified' => 0, 'consumption_released' => false];
        }

        $envelope->refresh();

        $notified = $this->notify($envelope, $result['notify']);
        $released = $this->releaseIfUntouched($envelope, $result['signed']);

        return ['canceled' => true, 'notified' => $notified, 'consumption_released' => $released];
    }

    /**
     * As mensagens saem por `EnvelopeNotifications`, o mesmo lugar que o fluxo público usa
     * pelo contrato `SignerNotifications`: cancelamento e recusa dizem a mesma coisa, do
     * mesmo jeito, e respeitam as mesmas preferências.
     *
     * @param  list<int>  $recipientIds
     */
    private function notify(Envelope $envelope, array $recipientIds): int
    {
        if ($recipientIds === []) {
            return 0;
        }

        /** @var list<Recipient> $recipients */
        $recipients = $envelope->recipients()->whereIn('id', $recipientIds)->get()->values()->all();

        $this->notifications->notifyEnvelopeClosed($envelope, $recipients, 'envelope_canceled');

        return count($recipients);
    }

    private function releaseIfUntouched(Envelope $envelope, int $signed): bool
    {
        if ($signed > 0) {
            return false;
        }

        $consumption = $this->ledger->forEnvelopeSend($envelope);

        if ($consumption === null || $consumption->status === PlanConsumptionStatus::Released) {
            return false;
        }

        $this->ledger->release($consumption, $envelope, 'envelope_canceled');

        return true;
    }
}
