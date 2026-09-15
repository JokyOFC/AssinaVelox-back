<?php

namespace App\Services\Batch;

use App\Enums\AuditEventType;
use App\Enums\AuthMethod;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use App\Services\Batch\Models\BatchSigningItem;
use App\Services\Batch\Models\BatchSigningSession;
use App\Services\Identity\IdentityCaptures;
use App\Services\InPerson\ParticipantContexts;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\EnvelopeExpiration;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\RecordAcceptance;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Itens do lote: estado, abertura e autorização ITEM A ITEM (docs/fase-2/presencial-e-lote.md §3.4).
 *
 * ## Autorização por item — o que isso significa no código
 *
 * - **Não existe "autorizar todos"**: cada POST autoriza exatamente um item, o do caminho.
 * - Abrir um item cria a sessão de assinatura DAQUELE envelope (`signing_sessions`, a mesma
 *   do fluxo individual), autenticada pelo código do lote. Ela carrega a versão congelada, a
 *   marca de apresentação de cada documento e o token de autorização preso ao snapshot
 *   daquela tela.
 * - Autorizar chama o MESMO {@see RecordAcceptance} do fluxo individual, com o contexto, a
 *   sessão e o payload daquele item: um aceite próprio, com snapshot, campos, documentos,
 *   IP/navegador e revalidação sob lock próprios. O `UNIQUE(recipient_id)` dos aceites
 *   continua valendo: duas autorizações simultâneas do mesmo item gravam um aceite.
 * - Uma falha num item (campo obrigatório, envelope cancelado no meio, sessão vencida) é
 *   registrada naquele item e não toca nos demais.
 *
 * ## Itens que o lote não autoriza
 *
 * Expirados, recusados, cancelados, encerrados, fora da vez: aparecem com o estado e sem
 * botão. Itens cujo remetente exigiu autenticação diferente do código por e-mail (SMS,
 * WhatsApp, PIN) ou foto do participante aparecem como "abrir pelo link individual": o lote
 * nunca rebaixa a autenticação que o remetente escolheu.
 */
final class BatchItems
{
    public function __construct(
        private readonly ParticipantContexts $contexts,
        private readonly SignerSessions $sessions,
        private readonly SenderPins $pins,
        private readonly IdentityCaptures $captures,
        private readonly RecordAcceptance $acceptances,
        private readonly BatchChallenges $challenges,
    ) {}

    /**
     * @return Collection<int, BatchSigningItem>
     */
    public function itemsOf(BatchSigningSession $batch): Collection
    {
        /** @var Collection<int, BatchSigningItem> */
        return BatchSigningItem::withoutOrganizationScope()
            ->where('batch_signing_session_id', $batch->getKey())
            ->where('organization_id', $batch->organization_id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    public function find(BatchSigningSession $batch, string $ulid): ?BatchSigningItem
    {
        /** @var BatchSigningItem|null */
        return BatchSigningItem::withoutOrganizationScope()
            ->where('batch_signing_session_id', $batch->getKey())
            ->where('organization_id', $batch->organization_id)
            ->where('ulid', $ulid)
            ->first();
    }

    /**
     * Estado atual do item, decidido no servidor a cada leitura.
     *
     * @return array{state: string, label: string, authorizable: bool, reason: string|null, context: SignerContext|null, recipient: Recipient|null, envelope: Envelope|null}
     */
    public function describe(BatchSigningItem $item): array
    {
        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->whereKey($item->recipient_id)->first();
        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($item->envelope_id)->first();

        $result = fn (string $state, string $label, ?string $reason = null, ?SignerContext $context = null): array => [
            'state' => $state,
            'label' => $label,
            'authorizable' => $state === 'available',
            'reason' => $reason,
            'context' => $context,
            'recipient' => $recipient,
            'envelope' => $envelope,
        ];

        if ($recipient === null || $envelope === null
            || $recipient->organization_id !== $item->organization_id
            || $envelope->organization_id !== $item->organization_id
            || $recipient->envelope_id !== $envelope->getKey()) {
            return $result('closed', 'Indisponível');
        }

        $envelope = EnvelopeExpiration::revalidate($envelope);
        $recipient = Recipient::withoutOrganizationScope()->whereKey($recipient->getKey())->first() ?? $recipient;

        // O código do lote prova a posse do e-mail do LOTE. Se o remetente corrigiu depois o
        // e-mail do participante deste item, o dono do endereço antigo não pode mais tocá-lo —
        // do mesmo jeito que a troca de e-mail revoga o link individual.
        if (! $this->emailStillMatches($item, $recipient)) {
            return $result('closed', 'Indisponível', 'O e-mail deste participante foi alterado por quem enviou. Use o link recebido no endereço atual.');
        }

        if ($recipient->status === RecipientStatus::Signed
            || SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $recipient->getKey())->exists()) {
            return $result('done', $item->status === BatchSigningItem::STATUS_AUTHORIZED ? 'Autorizado neste lote' : 'Aceite já registrado');
        }

        if ($recipient->status === RecipientStatus::Refused) {
            return $result('refused', 'Você recusou este documento');
        }

        // Fase 3 §3.3 (F-FLOW): quem delegou não responde mais; o delegado tem o próprio convite.
        if ($recipient->status === RecipientStatus::Delegated) {
            return $result('closed', 'Delegado a outra pessoa');
        }

        if ($recipient->status === RecipientStatus::Expired || $envelope->status === EnvelopeStatus::Expired) {
            return $result('expired', 'Prazo encerrado');
        }

        if ($recipient->status === RecipientStatus::Canceled || $envelope->status === EnvelopeStatus::Canceled) {
            return $result('canceled', 'Cancelado pelo remetente');
        }

        if ($envelope->status !== EnvelopeStatus::InProgress) {
            return $result('closed', 'Coleta encerrada');
        }

        // Fase 3 §3.3 (F-FLOW): com etapas a vez vale também no paralelo (sem etapas = sequencial).
        if ($envelope->hasTurns() && $recipient->order_index > $envelope->current_order) {
            return $result('waiting', 'Aguardando outro participante');
        }

        $context = $this->contexts->for($recipient);

        if ($context === null || ! $context->isActive() || $context->action() === null) {
            return $result('closed', 'Indisponível');
        }

        $individual = $this->individualOnlyReason($context);

        if ($individual !== null) {
            return $result('individual', 'Abrir pelo link individual', $individual, $context);
        }

        return $result('available', 'Aguardando sua autorização', null, $context);
    }

    /**
     * O e-mail ATUAL do participante do item ainda é o e-mail cuja posse o lote prova.
     */
    private function emailStillMatches(BatchSigningItem $item, Recipient $recipient): bool
    {
        /** @var BatchSigningSession|null $batch */
        $batch = BatchSigningSession::withoutOrganizationScope()->whereKey($item->batch_signing_session_id)->first();

        return $batch !== null
            && $batch->organization_id === $item->organization_id
            && hash_equals($batch->email_digest, BatchLinks::emailDigest((string) $recipient->email));
    }

    /**
     * Motivo pelo qual o item só pode ser autorizado pelo link individual, ou null.
     */
    public function individualOnlyReason(SignerContext $context): ?string
    {
        if ($context->recipient->auth_method !== AuthMethod::EmailOtp) {
            return sprintf(
                'Quem enviou pediu a confirmação por %s para este documento. Abra-o pelo link individual recebido.',
                $context->recipient->auth_method->channel()->label(),
            );
        }

        if ($this->pins->requiredFor($context->recipient)) {
            return 'Quem enviou combinou um PIN com você para este documento. Abra-o pelo link individual recebido.';
        }

        if ($this->captures->isRequiredFor($context)) {
            return 'Quem enviou pediu fotos para o registro do aceite deste documento. Abra-o pelo link individual recebido.';
        }

        return null;
    }

    /**
     * Abre o item: cria (ou reaproveita, se for deste navegador e deste item) a sessão de
     * assinatura DAQUELE envelope, autenticada pelo código do lote.
     *
     * @throws SigningRejectedException
     */
    public function open(BatchSigningSession $batch, BatchSigningItem $item, Request $request): SigningSession
    {
        $described = $this->describe($item);

        if (! $described['authorizable'] || $described['context'] === null) {
            throw SigningRejectedException::conflict('item_not_authorizable', $described['reason'] ?? $described['label'].'.');
        }

        $context = $described['context'];
        $current = $this->session($item, $context, $request);

        if ($current !== null) {
            return $current;
        }

        $session = $this->sessions->authenticate($this->sessions->startPending($context, $request), $context, $request);
        $session->refresh();

        $item->forceFill([
            'signing_session_id' => $session->getKey(),
            'status' => $item->status === BatchSigningItem::STATUS_AUTHORIZED ? $item->status : BatchSigningItem::STATUS_OPEN,
            'opened_at' => Carbon::now(),
        ])->save();

        $challenge = $this->challenges->lastVerified($batch);

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::SessionStarted, [
            'session_ulid' => $session->ulid,
            'auth_method' => $context->recipient->auth_method->value,
            'expires_at' => $session->expires_at->toIso8601String(),
            'batch' => $batch->ulid,
        ]);

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::BatchItemOpened, [
            'batch' => $batch->ulid,
            'item' => $item->ulid,
            'session_ulid' => $session->ulid,
            'challenge' => $challenge?->ulid,
            'channel' => 'email',
        ]);

        return $session;
    }

    /**
     * Sessão de assinatura aberta para ESTE item neste navegador, se ainda vale.
     */
    public function session(BatchSigningItem $item, SignerContext $context, Request $request): ?SigningSession
    {
        $session = $this->sessions->current($context, $request);

        if ($session === null || $item->signing_session_id === null || $session->getKey() !== $item->signing_session_id) {
            return null;
        }

        return $session;
    }

    /**
     * Autoriza UM item: grava o aceite daquele envelope pelo `RecordAcceptance`.
     *
     * @param  array{signature: array<string, mixed>, initials?: array<string, mixed>|null, fields?: array<string, mixed>, authorization: string}  $payload
     *
     * @throws SigningRejectedException
     */
    public function authorize(BatchSigningSession $batch, BatchSigningItem $item, Request $request, array $payload): SignatureAcceptance
    {
        $described = $this->describe($item);
        $context = $described['context'];

        if (! $described['authorizable'] || $context === null) {
            $exception = SigningRejectedException::conflict(
                $described['state'] === 'done' ? 'already_signed' : 'item_not_authorizable',
                $described['state'] === 'done'
                    ? 'O aceite deste documento já foi registrado.'
                    : ($described['reason'] ?? 'Este documento não pode ser autorizado: '.mb_strtolower($described['label']).'.'),
            );

            $this->recordFailure($batch, $item, $described['envelope'], $described['recipient'], $exception->errorCode);

            throw $exception;
        }

        $session = $this->session($item, $context, $request);

        if ($session === null) {
            $exception = SigningRejectedException::conflict(
                'session_missing',
                'A sessão deste documento expirou ou foi aberta em outro lugar. Abra o documento de novo, confira e autorize.',
            );

            $this->recordFailure($batch, $item, $context->envelope, $context->recipient, $exception->errorCode);

            throw $exception;
        }

        try {
            $acceptance = $this->acceptances->handle($context, $session, $request, $payload);
        } catch (SigningRejectedException $exception) {
            $this->recordFailure($batch, $item, $context->envelope, $context->recipient, $exception->errorCode);

            throw $exception;
        }

        $item->forceFill([
            'status' => BatchSigningItem::STATUS_AUTHORIZED,
            'signature_acceptance_id' => $acceptance->getKey(),
            'authorized_at' => Carbon::now(),
            'last_error_code' => null,
            'last_error_at' => null,
        ])->save();

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::BatchItemAuthorized, [
            'batch' => $batch->ulid,
            'item' => $item->ulid,
            'acceptance_ulid' => $acceptance->ulid,
        ]);

        return $acceptance;
    }

    /**
     * Revoga as sessões de assinatura dos itens deste lote abertas neste navegador ("Sair").
     */
    public function revokeOpenSessions(BatchSigningSession $batch, Request $request): void
    {
        foreach ($this->itemsOf($batch) as $item) {
            if ($item->signing_session_id === null) {
                continue;
            }

            $described = $this->describe($item);
            $context = $described['context'];

            if ($context === null) {
                continue;
            }

            $session = $this->session($item, $context, $request);

            if ($session !== null) {
                $this->sessions->revoke($session, $context, $request);
            }
        }
    }

    private function recordFailure(BatchSigningSession $batch, BatchSigningItem $item, ?Envelope $envelope, ?Recipient $recipient, string $reason): void
    {
        $item->forceFill(['last_error_code' => $reason, 'last_error_at' => Carbon::now()])->save();

        $payload = ['batch' => $batch->ulid, 'item' => $item->ulid, 'reason' => $reason];

        if ($envelope !== null && $recipient !== null) {
            SignerAudit::record($envelope, $recipient, AuditEventType::BatchItemFailed, $payload);

            return;
        }

        BatchAudit::record($batch, AuditEventType::BatchItemFailed, ['item' => $item->ulid, 'reason' => $reason]);
    }
}
