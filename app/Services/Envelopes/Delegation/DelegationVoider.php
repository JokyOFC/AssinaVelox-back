<?php

namespace App\Services\Envelopes\Delegation;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Delegation;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Services\Envelopes\Steps\StepProgression;
use App\Services\Signing\SignerAudit;
use Illuminate\Support\Carbon;

/**
 * Pedidos de delegação que perderam o objeto ficam "sem efeito" NA PRÓPRIA TRANSIÇÃO
 * (revisão adversarial da onda F — docs/fase-3/etapas-e-delegacao.md §3.3).
 *
 * Chamado DENTRO das transações que já travaram o envelope: aceite (RecordAcceptance::advance,
 * que também cobre etapa pulada e conclusão), recusa (RecordRefusal), cancelamento
 * (CancelEnvelope) e expiração (ExpireEnvelopes). Sem pedido pendente no envelope — sempre, com
 * a flag `delegation` desligada — é uma consulta e nada mais.
 *
 * Um pedido pendente perde o objeto quando a coleta não está mais em andamento ou quando quem
 * pediu já não pode delegar (aceitou, recusou, foi cancelado porque a etapa dele não se aplicou).
 * A transição é um UPDATE condicional (`status = pending`): execuções concorrentes gravam um único
 * evento `delegation.voided`.
 */
final class DelegationVoider
{
    public const NOTE_CLOSED = 'A coleta terminou antes da decisão.';

    public const NOTE_ACTED = 'O participante já respondeu antes da decisão.';

    public const NOTE_STEP_SKIPPED = 'A etapa do participante não se aplicou.';

    public static function voidStale(Envelope $locked, ?string $correlationId = null): int
    {
        $pending = Delegation::withoutOrganizationScope()
            ->where('envelope_id', $locked->getKey())
            ->where('status', Delegation::STATUS_PENDING)
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $closed = $locked->status !== EnvelopeStatus::InProgress;
        $voided = 0;

        foreach ($pending as $delegation) {
            /** @var Recipient|null $from */
            $from = Recipient::withoutOrganizationScope()->whereKey($delegation->from_recipient_id)->first();

            $acted = $from === null
                || ! $from->status->canBeDelegated()
                || SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $from->getKey())->exists();

            if (! $closed && ! $acted) {
                continue;
            }

            [$code, $note] = match (true) {
                $closed => ['closed', self::NOTE_CLOSED],
                $from !== null && $from->status === RecipientStatus::Canceled
                    && $from->getAttribute('status_reason') === StepProgression::REASON_STEP_SKIPPED => ['step_skipped', self::NOTE_STEP_SKIPPED],
                default => ['already_acted', self::NOTE_ACTED],
            };

            $affected = Delegation::withoutOrganizationScope()
                ->whereKey($delegation->getKey())
                ->where('status', Delegation::STATUS_PENDING)
                ->update([
                    'status' => Delegation::STATUS_VOID,
                    'decision_note' => $note,
                    'updated_at' => Carbon::now(),
                ]);

            if ($affected !== 1) {
                continue;
            }

            $voided++;

            SignerAudit::system($locked, AuditEventType::DelegationVoided, [
                'delegation' => $delegation->ulid,
                'from' => $from?->ulid,
                'reason' => $code,
            ], $from, $correlationId);
        }

        return $voided;
    }
}
