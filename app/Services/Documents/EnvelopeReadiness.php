<?php

namespace App\Services\Documents;

use App\Enums\DocumentProcessingStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\SigningOrder;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Anchors\SuggestionGate;
use App\Services\Envelopes\Steps\StepTurns;
use Illuminate\Support\Collection;

/**
 * Recomputa o status de um envelope EM PREPARAÇÃO (arquitetura §3.2).
 *
 * Contrato público chamado por quem altera qualquer uma das três partes do rascunho:
 * documento (B-DOC), destinatários e campos (B-FIELDS). Depois de gravar sua parte,
 * chame `recompute($envelope)`.
 *
 *   draft      — falta documento pronto, destinatário ou campo válido
 *   preparing  — há documento em processamento (uploaded | converting)
 *   ready      — TODOS os documentos `ready` E participantes coerentes E campos válidos
 *
 * ## Papéis (Fase 2 §2.4)
 *
 * - é preciso pelo menos um `signer`;
 * - todo `signer` e `witness` precisa de ≥ 1 campo de assinatura obrigatório;
 * - `approver` não pode ter campo de assinatura nem de rubrica;
 * - `viewer` não pode ter campo nenhum e não entra na conta da ordem de assinatura.
 *
 * Com todos os participantes `signer` (Fase 1) as regras acima são exatamente as de antes.
 *
 * ## Vários documentos (Fase 2 §2.3)
 *
 * Todos os documentos precisam estar `ready` com versão exibível; os campos contam em
 * qualquer um deles (a assinatura de um participante pode estar num só arquivo — o aceite
 * dele cobre o conjunto).
 *
 * Envelopes já enviados (in_progress em diante) e terminais nunca são tocados: o método
 * devolve o status atual sem gravar nada. Também não lança em transição inválida — ele é
 * chamado em muitos pontos e só decide entre draft/preparing/ready, que são mutuamente
 * alcançáveis.
 */
class EnvelopeReadiness
{
    /**
     * Recalcula e persiste o status do envelope. Devolve o status resultante.
     */
    public function recompute(Envelope $envelope): EnvelopeStatus
    {
        if (! $envelope->status->isDraftLike()) {
            return $envelope->status;
        }

        $target = $this->computeStatus($envelope);

        if ($envelope->status !== $target) {
            $envelope->status = $target;
            $envelope->save();
        }

        return $target;
    }

    /**
     * Status que o envelope deveria ter, sem gravar.
     */
    public function computeStatus(Envelope $envelope): EnvelopeStatus
    {
        $completeness = $this->completeness($envelope);

        if ($completeness['document'] && $completeness['recipients'] && $completeness['fields']) {
            return EnvelopeStatus::Ready;
        }

        $processing = EnvelopeDocuments::ordered($envelope)
            ->contains(fn (Document $document): bool => ! $document->processing_status->isTerminal());

        if ($processing) {
            return EnvelopeStatus::Preparing;
        }

        return EnvelopeStatus::Draft;
    }

    /**
     * Completude por passo do wizard (ROUTES §2.6 `completeness`).
     *
     * @return array{document: bool, recipients: bool, fields: bool}
     */
    public function completeness(Envelope $envelope): array
    {
        $documents = EnvelopeDocuments::ordered($envelope);

        $documentReady = $documents->isNotEmpty()
            && $documents->every(fn (Document $document): bool => $document->processing_status === DocumentProcessingStatus::Ready
                && $document->current_version_id !== null);

        $recipients = $this->recipients($envelope);
        $participants = $recipients->filter(fn (Recipient $recipient): bool => $recipient->participates())->values();
        $hasSigner = $participants->contains(fn (Recipient $recipient): bool => $recipient->role === RecipientRole::Signer);

        return [
            // A ordem de assinatura faz parte da completude do passo 2: um envelope
            // `sequential` cujos signatários estão todos na mesma vez não cumpre a ordem
            // que a interface promete.
            'document' => $documentReady,
            'recipients' => $hasSigner && $this->signingOrderIsCoherent($envelope),
            'fields' => $documentReady
                && $participants->isNotEmpty()
                && $this->fieldsSatisfyRoles($envelope, $documents, $recipients)
                // Fase 3 §3.2 (F-ANCHOR): sugestão de campo sem revisão impede o `ready`.
                // Flag global desligada = nenhuma consulta (SuggestionGate::blocks volta cedo).
                && ! SuggestionGate::blocks($envelope),
        ];
    }

    /**
     * `envelopes.signing_order` e `recipients.order_index` têm de contar a mesma história.
     *
     * Quem grava `order_index` é `Envelopes\RecipientSync` (sequencial: 1..N na ordem da
     * lista; paralelo: todos em 1; visualizadores ficam em 0 e não entram na conta). O
     * autosave do passo 1 grava `signing_order` sozinho, e sem esta invariante um envelope
     * podia ficar `sequential` com todo mundo em 1 — caso em que
     * `InvitationDispatcher::pendingForCurrentTurn()` convida todos de uma vez e
     * `RecordAcceptance` deixa qualquer um assinar, enquanto as telas continuam dizendo
     * "Assinatura em ordem". O inverso também quebra: `parallel` com 1..N faz o dispatcher
     * convidar só o primeiro e os demais nunca recebem nada.
     *
     * Verificação única, usada tanto para gravar o status quanto para montar a lista de
     * pendências da tela (`Envelopes\EnvelopeReadiness::recipientIssues()`).
     */
    public function signingOrderIsCoherent(Envelope $envelope): bool
    {
        // Fase 3 §3.3 (F-FLOW): com etapas, a vez vem da etapa (no paralelo, a vez É a etapa).
        // Sem etapas (o padrão e com a flag desligada) a regra abaixo é a de sempre.
        if ($envelope->usesSigningSteps()) {
            return StepTurns::isCoherent($envelope);
        }

        $indexes = array_map(
            'intval',
            Recipient::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->participating()
                ->orderBy('order_index')
                ->orderBy('id')
                ->pluck('order_index')
                ->all(),
        );

        if ($indexes === []) {
            return true;
        }

        if ($envelope->signing_order === SigningOrder::Sequential) {
            return $indexes === range(1, count($indexes));
        }

        return array_values(array_unique($indexes)) === [1];
    }

    /**
     * Regras de campo por papel, sobre as versões exibíveis CORRENTES dos documentos.
     * Campos que apontam para uma versão antiga não contam — o documento foi substituído e
     * os campos serão descartados.
     *
     * `required` faz parte da regra da assinatura: um campo de assinatura opcional não
     * satisfaz "todo signatário precisa de pelo menos um campo de assinatura". Sem este
     * filtro havia DUAS definições da mesma invariante — esta, que grava o status, e a de
     * `Envelopes\EnvelopeReadiness::fieldIssues()`, que monta a lista de pendências da tela
     * — e elas discordavam.
     *
     * @param  Collection<int, Document>  $documents
     * @param  Collection<int, Recipient>  $recipients
     */
    private function fieldsSatisfyRoles(Envelope $envelope, Collection $documents, Collection $recipients): bool
    {
        $versionIds = array_values(array_map(
            'intval',
            array_filter($documents->pluck('current_version_id')->all(), static fn ($id): bool => $id !== null),
        ));

        if ($versionIds === []) {
            return false;
        }

        /** @var Collection<int, SigningField> $fields */
        $fields = SigningField::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('document_version_id', $versionIds)
            ->get(['id', 'recipient_id', 'type', 'required']);

        $byRecipient = $fields->groupBy('recipient_id');

        foreach ($recipients as $recipient) {
            /** @var Collection<int, SigningField> $mine */
            $mine = $byRecipient->get($recipient->getKey(), collect());

            if (! $recipient->role->allowsFields() && $mine->isNotEmpty()) {
                return false;
            }

            if (! $recipient->role->allowsVisualSignature()
                && $mine->contains(fn (SigningField $field): bool => $field->type->isImageBased())) {
                return false;
            }

            if ($recipient->role->requiresSignatureField()
                && ! $mine->contains(fn (SigningField $field): bool => $field->type === FieldType::Signature && $field->required)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Collection<int, Recipient>
     */
    private function recipients(Envelope $envelope): Collection
    {
        return Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->get();
    }
}
