<?php

namespace App\Services\Documents;

use App\Enums\DocumentProcessingStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Models\Envelope;
use App\Models\SigningField;

/**
 * Recomputa o status de um envelope EM PREPARAÇÃO (arquitetura §3.2).
 *
 * Contrato público chamado por quem altera qualquer uma das três partes do rascunho:
 * documento (B-DOC), destinatários e campos (B-FIELDS). Depois de gravar sua parte,
 * chame `recompute($envelope)`.
 *
 *   draft      — falta documento pronto, destinatário ou campo válido
 *   preparing  — há documento em processamento (uploaded | converting)
 *   ready      — documento `ready` E ≥ 1 destinatário E campos válidos
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

        $document = $envelope->document;

        if ($document !== null && ! $document->processing_status->isTerminal()) {
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
        $document = $envelope->document;

        $documentReady = $document !== null
            && $document->processing_status === DocumentProcessingStatus::Ready
            && $document->current_version_id !== null;

        $recipientIds = array_map('intval', $envelope->recipients()->pluck('id')->all());

        return [
            'document' => $documentReady,
            'recipients' => $recipientIds !== [],
            'fields' => $documentReady
                && $recipientIds !== []
                && $this->everyRecipientHasSignatureField($envelope, $recipientIds),
        ];
    }

    /**
     * Todo destinatário precisa de pelo menos um campo de assinatura na versão exibível
     * corrente (ROUTES §2.6, passo 3). Campos que apontam para uma versão antiga do
     * documento não contam — o documento foi substituído e os campos serão descartados.
     *
     * @param  array<int, int>  $recipientIds
     */
    private function everyRecipientHasSignatureField(Envelope $envelope, array $recipientIds): bool
    {
        $versionId = $envelope->document?->current_version_id;

        if ($versionId === null) {
            return false;
        }

        $withSignature = SigningField::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('document_version_id', $versionId)
            ->where('type', FieldType::Signature->value)
            // `required` faz parte da regra: um campo de assinatura opcional não satisfaz
            // "todo signatário precisa de pelo menos um campo de assinatura". Sem este
            // filtro havia DUAS definições da mesma invariante — esta, que grava o status,
            // e a de `Envelopes\EnvelopeReadiness::fieldIssues()`, que monta a lista de
            // pendências da tela — e elas discordavam.
            ->where('required', true)
            ->distinct()
            ->pluck('recipient_id')
            ->all();
        $withSignature = array_map('intval', $withSignature);

        foreach ($recipientIds as $recipientId) {
            if (! in_array($recipientId, $withSignature, true)) {
                return false;
            }
        }

        return true;
    }
}
