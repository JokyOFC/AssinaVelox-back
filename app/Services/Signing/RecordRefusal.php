<?php

namespace App\Services\Signing;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Models\SignatureAcceptance;
use App\Services\Signing\Contracts\SignerNotifications;
use App\Services\Signing\Exceptions\SigningRejectedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recusa do signatário (arquitetura §4.6, ROUTES §3.3).
 *
 * ## Divisão de responsabilidade
 *
 * Este serviço é o **único escritor de estado** desta transição: marca o destinatário
 * `refused`, aplica a política da organização, encerra o envelope, cancela os demais
 * pendentes, revoga links e sessões e grava a trilha — tudo sob lock, em uma transação.
 * Produzir as **mensagens** que decorrem disso (aviso ao remetente com o motivo, aviso de
 * encerramento a quem já tinha sido convidado) é do módulo de envio, pelo contrato
 * {@see SignerNotifications}.
 *
 * ## Por que o motivo é obrigatório
 *
 * A recusa é uma manifestação de vontade tanto quanto o aceite: vai para a trilha e é enviada
 * ao remetente. Um "não" sem motivo obriga quem enviou a adivinhar. Mínimo de 10 caracteres,
 * máximo de 500.
 *
 * ## Depois da recusa
 *
 * Nenhum aceite entra, nem de uma aba já aberta com sessão válida: os links do envelope são
 * revogados na hora (o convite deixa de resolver, antes de qualquer tela ser montada) e o
 * aceite revalida o envelope sob lock e encontra `refused`. A recusa é irreversível pelo
 * signatário; o remetente pode duplicar o envelope e recomeçar.
 */
final class RecordRefusal
{
    public const MIN_REASON = 10;

    public const MAX_REASON = 500;

    public function __construct(
        private readonly SignerSessions $sessions,
        private readonly SignerNotifier $notifier,
    ) {}

    /**
     * @throws SigningRejectedException
     */
    public function handle(SignerContext $context, string $reason): Recipient
    {
        $reason = trim($reason);
        $correlationId = SignerTokens::correlationId();

        /** @var array{recipient: Recipient, envelope: Envelope, canceled: list<Recipient>, closed: bool} $outcome */
        $outcome = DB::transaction(function () use ($context, $reason, $correlationId): array {
            /** @var Envelope|null $envelope */
            $envelope = Envelope::withoutOrganizationScope()
                ->whereKey($context->envelope->getKey())
                ->lockForUpdate()
                ->first();

            /** @var Recipient|null $recipient */
            $recipient = Recipient::withoutOrganizationScope()
                ->whereKey($context->recipient->getKey())
                ->first();

            if ($envelope === null || $recipient === null || $envelope->status !== EnvelopeStatus::InProgress) {
                throw SigningRejectedException::conflict('not_refusable', 'Este documento não está mais disponível para assinatura.');
            }

            if (SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $recipient->getKey())->exists()) {
                throw SigningRejectedException::conflict('already_signed', 'Seu aceite já foi registrado: não é possível recusar depois de assinar.');
            }

            if (! $recipient->status->isPendingSignature()) {
                throw SigningRejectedException::conflict('not_refusable', 'Este documento não está mais disponível para assinatura.');
            }

            $now = Carbon::now();

            // pending → notified → viewed → refused: recusar prova que a pessoa viu a tela,
            // e a máquina de estados não permite pular `viewed`.
            if ($recipient->status === RecipientStatus::Notified) {
                $recipient->transitionTo(RecipientStatus::Viewed);
            }

            $recipient->transitionTo(RecipientStatus::Refused);
            $recipient->refused_at = $now;
            $recipient->refusal_reason = $reason;
            $recipient->save();

            SignerAudit::record($envelope, $recipient, AuditEventType::RecipientRefused, [
                // O motivo já está em `recipients.refusal_reason` e vai ao remetente; na
                // trilha fica só a medida, para não duplicar texto livre vindo do público.
                'reason_length' => mb_strlen($reason),
                'order_index' => $recipient->order_index,
            ], $correlationId);

            $closed = $this->closesEnvelope($context);
            $canceled = $closed ? $this->closeEnvelope($envelope, $recipient, $reason, $now, $correlationId) : [];

            return ['recipient' => $recipient, 'envelope' => $envelope, 'canceled' => $canceled, 'closed' => $closed];
        });

        $this->sessions->revokeAllFor($outcome['recipient']);

        foreach ($outcome['canceled'] as $canceled) {
            $this->sessions->revokeAllFor($canceled);
        }

        $this->notifier->notifySenderRefused($outcome['envelope'], $outcome['recipient']);

        if ($outcome['closed']) {
            $this->notifier->notifyEnvelopeClosed($outcome['envelope'], $outcome['canceled'], 'recipient_refused');
        }

        return $outcome['recipient'];
    }

    /**
     * Encerra o envelope e cancela quem ainda estava pendente.
     *
     * Os demais viram `canceled`, não `refused`: eles não recusaram nada, e registrar o
     * contrário seria falsear a trilha e a página de evidências.
     *
     * @return list<Recipient>
     */
    private function closeEnvelope(Envelope $envelope, Recipient $refusedBy, string $reason, Carbon $now, string $correlationId): array
    {
        $envelope->transitionTo(EnvelopeStatus::Refused);
        $envelope->refused_at = $now;

        // O motivo e quem recusou ficam no envelope para que as telas do remetente e as
        // mensagens de encerramento não precisem varrer os destinatários.
        $envelope->settings = array_replace($envelope->settings ?? [], [
            'refusal_reason' => $reason,
            'refused_by' => $refusedBy->ulid,
        ]);

        $envelope->save();

        /** @var Collection<int, Recipient> $pending */
        $pending = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereKeyNot($refusedBy->getKey())
            ->whereIn('status', [
                RecipientStatus::Pending->value,
                RecipientStatus::Notified->value,
                RecipientStatus::Viewed->value,
            ])
            ->get();

        foreach ($pending as $other) {
            $other->transitionTo(RecipientStatus::Canceled);
            $other->save();
        }

        // Quem JÁ ASSINOU mantém o link, como na expiração e no cancelamento: é por ele que
        // a pessoa chega ao próprio comprovante de aceite e ao documento que assinou. A
        // recusa de outro participante encerra o pedido; não apaga, para quem já se
        // manifestou, a prova do que fez. Manter o link não reabre nada — o resolver recusa
        // assinar fora de `in_progress` e o envelope já está `refused`.
        $keepLinkFor = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('status', RecipientStatus::Signed->value)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->push((int) $refusedBy->getKey())
            ->unique()
            ->values()
            ->all();

        // Os convites dos DEMAIS não sobrevivem ao encerramento: o link deixa de abrir já na
        // resolução do token, antes de qualquer tela ser montada.
        //
        // O link de quem recusou continua válido de propósito. Sem ele, o próprio redirect
        // que o `sign.refuse` faz para `sign.show` cairia no 404 genérico — a pessoa clicaria
        // em "Recusar", confirmaria o motivo e receberia "Link inválido", como se o sistema
        // tivesse quebrado. A tela `refused` (ROUTES §3.4) existe justamente para confirmar a
        // recusa a quem a fez, e ela não entrega nada: `SignerLinkResolver::stateFor()` devolve
        // STATE_REFUSED, a sessão de assinatura já foi revogada acima, `Challenges::send()`
        // recusa qualquer novo código fora do estado ativo e `sign.download` exige aceite ou
        // sessão viva — nenhum dos dois existe aqui. Contar a alguém a própria recusa não é
        // vazamento; negá-la é um bug de interface.
        RecipientAccessLink::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereNotIn('recipient_id', $keepLinkFor)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);

        SignerAudit::system($envelope, AuditEventType::EnvelopeRefused, [
            'recipient' => $refusedBy->ulid,
            'has_reason' => $reason !== '',
            'canceled_recipients' => $pending->count(),
            'policy' => 'close_envelope',
        ], $refusedBy, $correlationId);

        /** @var list<Recipient> */
        return $pending->values()->all();
    }

    /**
     * Política de recusa da organização. Padrão da Fase 1: encerrar o envelope. Qualquer
     * outro valor é um ponto de extensão — nada além do padrão está implementado.
     */
    public function closesEnvelope(SignerContext $context): bool
    {
        return $context->organization->setting('refusal_policy', 'close_envelope') === 'close_envelope';
    }
}
